@extends('layouts.base')
@section('title',$page->title)
@section('body')
<main class="max-w-3xl mx-auto px-4 py-10"><header class="mb-8"><p class="text-sm text-slate-500">{{ $page->user->displayName() }}</p><h1 class="text-3xl font-bold mt-3">{{ $page->title }}</h1><p class="text-slate-600 mt-4 whitespace-pre-line">{{ $page->description }}</p></header>
@if($errors->any())<div role="alert" class="bg-red-50 text-red-700 p-4 rounded-lg mb-6">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($products->isEmpty())<div class="card p-8">No items are available right now. Please contact the merchant.</div>@else
<form class="card p-6 sm:p-8" method="POST" action="{{ route('commerce.checkout',$page->slug) }}">@csrf<input type="hidden" name="checkout_key" value="{{ $checkoutKey }}">
<fieldset><legend class="font-semibold text-lg mb-4">Choose a product or service</legend>@foreach($products as $product)<label class="flex items-start gap-3 border rounded-lg p-4 mb-3 cursor-pointer"><input type="radio" name="product_id" value="{{ $product->id }}" class="mt-1" required @checked(old('product_id')==$product->id || ($loop->first && !old('product_id')))><span><strong>{{ $product->name }}</strong><span class="block text-sm text-slate-500 mt-1 whitespace-pre-line">{{ $product->description }}</span><span class="block font-semibold mt-2">₹{{ number_format($product->amount_paise/100,2) }} each</span></span></label>@endforeach</fieldset>
<div class="mt-6"><x-input name="quantity" label="Quantity" type="number" min="1" max="100" value="1" required/><x-input name="name" label="Your name" maxlength="100" autocomplete="name" required/><x-input name="email" label="Email (optional, for merchant records)" type="email" maxlength="255" autocomplete="email"/><x-input name="coupon" label="Coupon code (optional)" maxlength="40"/></div>
<p class="text-sm text-slate-500 mb-5">Review your final amount and discount on the next screen. You can pay the full amount or in parts with UPI. Payments are confirmed by the merchant after checking their bank record. This is not AutoPay.</p><button class="btn btn-primary w-full">Review invoice</button></form>
<form method="POST" action="{{ route('commerce.new',$page->slug) }}" class="mt-5 text-center">@csrf<p class="text-xs text-slate-500 mb-3">Returning after a previous purchase? Review invoice reopens it to prevent duplicates.</p><button class="btn btn-secondary">Start a separate purchase</button></form>
@endif</main>
@endsection
