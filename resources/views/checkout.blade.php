@extends('layouts.main')

@section('title', 'Checkout')

@section('content')
    <section class="py-5">
        <div class="container">
            <h1 class="text-3xl font-bold mb-4">Checkout</h1>

            <!-- Cart Summary -->
   <div class="mb-4">
    <h2 class="text-2xl font-semibold mb-3">Cart Summary</h2>
    @if(count($cartItems) > 0)
        <table class="table-auto w-full border border-gray-200">
            <thead>
                <tr>
                    <th class="px-4 py-2 border-b">Image</th>
                    <th class="px-4 py-2 border-b">Name</th>
                    <th class="px-4 py-2 border-b">Price</th>
                    <th class="px-4 py-2 border-b">Quantity</th>
                    <th class="px-4 py-2 border-b">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($cartItems as $item)
                    <tr>
                        <td class="px-4 py-2 border-b">
                            <img src="{{ asset('storage/' . $item->product->image) }}" alt="{{ $item->product->name }}" class="w-20 h-20 object-cover">
                        </td>
                        <td class="px-4 py-2 border-b">{{ $item->product->name }}</td>
                        <td class="px-4 py-2 border-b">{{ number_format($item->price, 2) }} MAD</td>
                        <td class="px-4 py-2 border-b">{{ $item->quantity }}</td>
                        <td class="px-4 py-2 border-b">{{ number_format(($item->price * $item->quantity), 2) }} MAD</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="px-4 py-2 border-b text-right font-bold">Total:</td>
                    <td class="px-4 py-2 border-b font-bold">{{ number_format($total / 100, 2) }} MAD</td>
                </tr>
                <tr>
                    <td colspan="4" class="px-4 py-2 border-b text-right font-bold">Shipping:</td>
                    <td class="px-4 py-2 border-b font-bold">{{ number_format($shipping / 100, 2) }} MAD</td>
                </tr>
                <tr>
                    <td colspan="4" class="px-4 py-2 border-b text-right font-bold">Grand Total:</td>
                    <td class="px-4 py-2 border-b font-bold">{{ number_format(($total + $shipping) / 100, 2) }} MAD</td>
                </tr>
            </tfoot>
        </table>
    @else
        <p>Your cart is empty.</p>
    @endif
</div>

            <!-- Payment Method Selection -->
            <div class="mb-4">
                <h2 class="text-xl font-semibold mb-3">Select Payment Method</h2>
                <div>
                    <input type="radio" id="stripe" name="payment_method" value="stripe">
                    <label for="stripe">Pay with Stripe</label>

                    <input type="radio" id="paypal" name="payment_method" value="paypal" class="ml-4">
                    <label for="paypal">Pay with PayPal</label>
                </div>
            </div>

            <!-- Stripe Payment Form -->
            <form id="stripe-payment-form" action="/process-payment" method="POST" style="display: none;">
                @csrf
                <div id="card-element" class="mb-4"><!-- A Stripe Element will be inserted here. --></div>
                <div id="card-errors" role="alert" class="text-red-600 mb-4"></div>
            </form>

            <!-- Place Order Button -->
            <button id="place-order-button" class="btn btn-primary">Place Order</button>

            <!-- PayPal Form -->
            <form id="paypal-form" action="{{ route('paypal.create') }}" method="POST" style="display: none;">
                @csrf
            </form>

            <!-- Stripe JS -->
            <script src="https://js.stripe.com/v3/"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var stripe = Stripe('{{ config('services.stripe.key') }}');
                    var elements = stripe.elements();
                    var cardElement = elements.create('card');
                    cardElement.mount('#card-element');

                    // Show Stripe form when "Pay with Stripe" is selected
                    document.getElementById('stripe').addEventListener('change', function() {
                        document.getElementById('stripe-payment-form').style.display = 'block';
                        document.getElementById('paypal-form').style.display = 'none';
                    });

                    // Show PayPal form when "Pay with PayPal" is selected
                    document.getElementById('paypal').addEventListener('change', function() {
                        document.getElementById('stripe-payment-form').style.display = 'none';
                        document.getElementById('paypal-form').style.display = 'block';
                    });

                    document.getElementById('place-order-button').addEventListener('click', function(event) {
    event.preventDefault();

    if (document.getElementById('stripe').checked) {
        // Stripe payment process
        stripe.createPaymentMethod({
            type: 'card',
            card: cardElement,
        }).then(function(result) {
            if (result.error) {
                // Display error.message in #card-errors
                document.getElementById('card-errors').textContent = result.error.message;
            } else {
                // Send the PaymentMethod ID to your server
                fetch('/process-payment', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({ payment_method: result.paymentMethod.id })
                }).then(function(response) {
                    return response.json();
                }).then(function(data) {
                    if (data.redirect_url) {
                        window.location.href = data.redirect_url;
                    } else {
                        // Handle any other response or error
                        console.error('Unexpected response:', data);
                    }
                });
            }
        });
    } else if (document.getElementById('paypal').checked) {
        // Submit the PayPal form
        document.getElementById('paypal-form').submit();
    }
});

                });
            </script>
        </div>
    </section>
@endsection