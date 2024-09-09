<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    public function __construct()
    {
        // Apply auth middleware to all methods in this controller
        $this->middleware('auth');
    }

    // Get the total count of items in the cart
    public function getCartItemCount()
    {
        $userId = Auth::id();

        Log::info('Getting cart item count', ['userId' => $userId]);

        $cart = $this->getCart($userId);

        $count = $cart ? CartItem::where('cart_id', $cart->id)->sum('quantity') : 0;

        Log::info('Cart item count', ['count' => $count]);

        return $count;
    }

    // Add item to the cart
    public function addToCart(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        $quantity = (int) $request->input('quantity', 1);

        if ($quantity <= 0) {
            return redirect()->back()->withErrors('Quantity must be greater than zero.');
        }

        $userId = Auth::id();

        Log::info('Adding item to cart', [
            'productId' => $id,
            'quantity' => $quantity,
            'userId' => $userId
        ]);

        $cart = $this->getCart($userId);

        if (!$cart) {
            Log::error('Failed to create or retrieve cart', ['userId' => $userId]);
            return redirect()->back()->withErrors('Unable to create or retrieve the cart.');
        }

        $cartItem = CartItem::where('cart_id', $cart->id)
                             ->where('product_id', $id)
                             ->first();

        if ($cartItem) {
            $cartItem->quantity += $quantity;
            $cartItem->save();

            Log::info('Updated cart item quantity', [
                'cartItemId' => $cartItem->id,
                'newQuantity' => $cartItem->quantity
            ]);
        } else {
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $id,
                'quantity' => $quantity,
                'price' => $product->price,
            ]);

            Log::info('Created new cart item', [
                'cartId' => $cart->id,
                'productId' => $id,
                'quantity' => $quantity
            ]);
        }

        return redirect()->route('cart.show');
    }

    // Show the cart
    public function showCart()
    {
        $userId = Auth::id();

        Log::info('Showing cart', ['userId' => $userId]);

        $cart = $this->getCart($userId);
        $items = $cart ? CartItem::where('cart_id', $cart->id)->with('product')->get() : [];

        Log::info('Cart items retrieved', ['items' => $items]);

        return view('cart', compact('items'));
    }

    // Remove item from the cart
    public function removeFromCart($id)
    {
        Log::info('Removing item from cart', ['cartItemId' => $id]);

        $cartItem = CartItem::findOrFail($id);
        $cartItem->delete();

        Log::info('Cart item removed', ['cartItemId' => $id]);

        return redirect()->route('cart.show');
    }

    // Helper function to get the appropriate cart for the user
    private function getCart($userId)
    {
        $cart = Cart::where('user_id', $userId)->first();

        if (!$cart) {
            $cart = Cart::create(['user_id' => $userId]);
            Log::info('Created new cart', ['cartId' => $cart->id, 'userId' => $userId]);
        }

        return $cart;
    }
}
