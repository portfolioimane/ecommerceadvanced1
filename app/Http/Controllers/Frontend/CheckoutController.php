<?php

namespace App\Http\Controllers\Frontend;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Models\Order;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class CheckoutController extends Controller
{
    private $paypal;

    public function __construct()
    {
        $this->paypal = new PayPalClient;
        $this->paypal->setApiCredentials(config('paypal'));
        $this->paypal->setAccessToken($this->paypal->getAccessToken());
    }

    public function checkout()
{
    $userId = Auth::id();

    // Fetch the cart for the logged-in user
    $cart = Cart::where('user_id', $userId)->first();

    // Initialize cart items and total amount
    $cartItems = [];
    $total = 0;

    if ($cart) {
        // Fetch items in the cart
        $cartItems = CartItem::where('cart_id', $cart->id)->with('product')->get();

        // Calculate the total amount
        $total = $cartItems->sum(function ($item) {
            return $item->quantity * $item->price;
        });
    }

    // Flat shipping rate
    $shipping = 50; // Example shipping rate

    // Pass cart items, total amount, and shipping rate to the view
    return view('checkout', compact('cartItems', 'total', 'shipping'));
}

/**********************************stripe*************************/
public function processPayment(Request $request)
{
    Stripe::setApiKey(config('services.stripe.secret'));

    $amountInCents = $this->calculateAmountInCents();

    if ($amountInCents < 50) {
        return $this->paymentFailed('The amount is below the minimum charge amount allowed.');
    }

    try {
        $paymentIntent = PaymentIntent::create([
            'amount' => $amountInCents,
            'currency' => 'mad',
            'payment_method' => $request->payment_method,
            'confirmation_method' => 'manual',
            'confirm' => true,
            'return_url' => route('payment.return'),
        ]);

        $order = $this->storeOrder($request, null, $amountInCents, 'pending');

        if ($paymentIntent->status === 'requires_action') {
            return response()->json(['redirect_url' => $paymentIntent->next_action->redirect_to_url->url]);
        } else {
            // Immediate success
            session()->forget('cart'); // Clear the cart here
            \Log::info('Immediate Success Redirect:', ['orderId' => $order->id]);
            return response()->json(['redirect_url' => route('success', ['orderId' => $order->id])]);
        }
    } catch (\Exception $e) {
        return $this->paymentFailed($e->getMessage());
    }
}


public function handlePaymentReturn(Request $request)
{
    $paymentIntentId = $request->query('payment_intent');

    if (!$paymentIntentId) {
        return redirect()->route('cancel')->with('error', 'Payment failed.');
    }

    try {
        $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

        if ($paymentIntent->status === 'succeeded') {
            $order = $this->storeOrder($request, $paymentIntent->id, $this->calculateAmount(), 'completed');

            if (!$order) {
                \Log::error('Order could not be created.');
                return $this->paymentFailed('Order could not be created.');
            }

            session()->forget('cart'); // Clear the cart here
            \Log::info('Cart Cleared.');

            return redirect()->route('success', ['orderId' => $order->id]);
        } else {
            return $this->paymentFailed('Payment failed.');
        }
    } catch (\Exception $e) {
        \Log::error('Payment Error:', ['error' => $e->getMessage()]);
        return $this->paymentFailed($e->getMessage());
    }
}




    /****************************************paypal *******************************/

public function createPayment(Request $request)
{
    $totalAmount = $this->calculateAmount(); // Amount in cents
    $totalAmountInDollars = number_format($totalAmount / 100, 2); // Convert cents to dollars

    \Log::info('Creating PayPal Payment', ['amount' => $totalAmountInDollars]);

    // Generate and store the order ID in the session
    $orderId = uniqid('order_', true);
    session()->put('order_id', $orderId);

    try {
        $paypalOrder = $this->paypal->createOrder([
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => 'transaction_test_number_' . $request->user()->id,
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => $totalAmountInDollars
                    ]
                ]
            ],
            'application_context' => [
                'cancel_url' => route('cancel'),
                'return_url' => route('paypalsuccess') // Use this route in return_url
            ]
        ]);

        \Log::info('PayPal Order Created', ['paypalOrder' => $paypalOrder]);

        if (!isset($paypalOrder['id'])) {
            \Log::error('PayPal Order Creation Failed', ['paypalOrder' => $paypalOrder]);
            return $this->paymentFailed('PayPal Order Creation Failed.');
        }

        return redirect($paypalOrder['links'][1]['href']);
    } catch (\Exception $e) {
        \Log::error('Exception in createPayment', ['error' => $e->getMessage()]);
        return $this->paymentFailed($e->getMessage());
    }
}



public function paypalsuccess(Request $request)
{
    $paymentId = $request->input('token');
    $orderId = session()->get('order_id'); // Retrieve the order ID from the session

    \Log::info('PayPal Success Handler Called', ['paymentId' => $paymentId, 'orderId' => $orderId]);

    if (!$paymentId) {
        \Log::error('Invalid Payment ID', ['paymentId' => $paymentId]);
        return redirect()->route('paypal.cancel')->with('error', 'Invalid payment ID.');
    }

    try {
        $payment = $this->paypal->capturePaymentOrder($paymentId);

        \Log::info('PayPal Payment Captured', ['payment' => $payment]);

        if ($payment['status'] === 'COMPLETED') {
            // Store the order with the retrieved order ID
            $order = $this->storeOrder($request, $payment['id'], $this->calculateAmount(), 'completed');

            if (!$order) {
                \Log::error('Order Creation Failed', ['payment' => $payment]);
                return $this->paymentFailed('Order could not be created.');
            }

            \Log::info('Order Created Successfully', ['order' => $order]);

            // Clear the cart
            session()->forget('cart');
            \Log::info('Cart Cleared');

            // Clear the order ID from the session
            session()->forget('order_id');

            return redirect()->route('success', ['orderId' => $order->id]);
        } else {
            \Log::error('PayPal Payment Capture Failed', ['payment' => $payment]);
            return $this->paymentFailed('PayPal Payment Capture Failed.');
        }
    } catch (\Exception $e) {
        \Log::error('Exception in paypalsuccess', ['error' => $e->getMessage()]);
        return $this->paymentFailed($e->getMessage());
    }
}



  public function success($orderId)
{
    $order = Order::find($orderId);

    if (!$order) {
        return redirect()->route('home')->with('error', 'Order not found.');
    }

    return view('success', ['order' => $order]);
}


    public function cancel()
    {
        return view('cancel');
    }


   



    private function calculateAmountInCents()
    {
        $cart = session()->get('cart', []);
        $totalAmountInDollars = array_reduce($cart, function ($carry, $item) {
            return $carry + ($item['price'] * $item['quantity']);
        }, 0);

        return (int)($totalAmountInDollars * 100);
    }

    private function calculateAmount()
    {
        $cart = session()->get('cart', []);
        return array_reduce($cart, function ($carry, $item) {
            return $carry + ($item['price'] * $item['quantity']);
        }, 0);
    }

private function storeOrder(Request $request, $paypalTransactionId, $amount, $status)
{
    try {
        // Validate request data
        $userId = $request->user()->id;
        if (!$userId) {
            \Log::error('Store Order Error: User ID is missing.');
            return null;
        }

        // Create the order
        $order = Order::create([
            'user_id' => $userId,
            'paypal_transaction_id' => $paypalTransactionId,
            'amount' => $amount,
            'status' => $status,
        ]);

        // Log the created order
        \Log::info('Order Created:', ['order' => $order]);

        return $order;
    } catch (\Exception $e) {
        // Log any exceptions
        \Log::error('Store Order Error:', ['error' => $e->getMessage()]);
        return null;
    }
}



  private function paymentFailed($message)
{
    Log::error('Payment Error: ' . $message);
    return response()->json(['error' => 'Payment failed. Please try again.'], 400);
}

}