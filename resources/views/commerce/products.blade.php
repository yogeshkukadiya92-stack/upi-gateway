@extends('layouts.app')
@section('title','Product & service catalogue')
@section('subtitle','Reusable prices for your hosted payment pages. All amounts are INR.')
@section('actions')<a class="btn btn-primary" href="{{ route('commerce.pages') }}">Payment pages</a>@endsection
@section('content')
<div class="grid lg:grid-cols-3 gap-6"><div class="lg:col-span-2 space-y-4">
@forelse($products as $product)<article class="card p-6"><div class="flex justify-between gap-4"><h2 class="font-semibold">{{ $product->name }}</h2><span>{{ $product->is_active?'Active':'Archived' }}</span></div><p class="text-sm text-slate-500 mt-2 whitespace-pre-line">{{ $product->description }}</p><p class="font-bold text-xl mt-3">₹{{ number_format($product->amount_paise/100,2) }}</p>
<details class="mt-4"><summary class="cursor-pointer text-brand-700">Edit details and price</summary><form method="POST" action="{{ route('commerce.products.update',$product) }}" class="mt-4">@csrf @method('PUT')<x-input name="name" label="Name" :value="$product->name" maxlength="100" required/><x-input name="description" label="Description" :value="$product->description" maxlength="2000"/><x-input name="amount" label="Price (INR)" type="number" min="1" max="100000" step="0.01" :value="$product->amount_paise/100" required/><button class="btn btn-secondary">Save changes</button></form></details>
<form method="POST" action="{{ route('commerce.products.toggle',$product) }}" class="mt-4">@csrf<button class="btn btn-secondary">{{ $product->is_active?'Archive':'Restore' }}</button></form></article>
@empty<div class="card p-8 text-slate-500">Add your first product or service, then put it on a payment page.</div>@endforelse
{{ $products->links() }}</div><form class="card p-6 h-fit" method="POST" action="{{ route('commerce.products.store') }}">@csrf<h2 class="font-semibold mb-5">Add product / service</h2><x-input name="name" label="Name" maxlength="100" required/><x-input name="description" label="Description (optional)" maxlength="2000"/><x-input name="amount" label="Price (INR)" type="number" min="1" max="100000" step="0.01" required/><button class="btn btn-primary">Add to catalogue</button><p class="text-xs text-slate-500 mt-4">Changing a price affects new purchases only. Existing invoices keep their original price.</p></form></div>
@endsection
