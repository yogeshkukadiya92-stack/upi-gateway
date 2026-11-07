@extends('layouts.app')
@section('title', 'Customers')
@section('subtitle', 'Manage contacts for your invoices and subscriptions.')
@section('content')
<div class="grid lg:grid-cols-3 gap-6">
<div class="lg:col-span-2"><form class="flex gap-2 mb-4" method="GET"><input aria-label="Search customers" name="q" class="input" value="{{ request('q') }}" placeholder="Name, email or mobile"><button class="btn btn-secondary">Search</button></form>
<div class="card divide-y divide-slate-100">
@forelse($customers as $customer)
<div class="p-5"><div class="flex justify-between gap-3"><div><h2 class="font-semibold">{{ $customer->name }}</h2><p class="text-sm text-slate-500">{{ $customer->email }} {{ $customer->mobile }}</p><p class="text-xs mt-1">{{ $customer->is_active ? 'Active' : 'Archived' }}</p></div><a class="btn btn-secondary" href="{{ route('billing.customers.edit', $customer) }}">Edit</a></div>
<form class="mt-3" method="POST" action="{{ route('billing.customers.toggle', $customer) }}">@csrf<button class="text-sm text-slate-500">{{ $customer->is_active ? 'Archive customer' : 'Restore customer' }}</button></form></div>
@empty<div class="p-10 text-center text-slate-500">Add your first customer to start billing.</div>@endforelse
</div><div class="mt-4">{{ $customers->links() }}</div></div>
<form method="POST" action="{{ route('billing.customers.store') }}" class="card p-6 h-fit">@csrf<h2 class="font-semibold mb-5">Add customer</h2>@include('billing.customer-fields', ['customer' => null])<button class="btn btn-primary">Add customer</button></form>
</div>
@endsection
