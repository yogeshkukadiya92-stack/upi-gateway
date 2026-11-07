@extends('layouts.app')
@section('title', 'Invoices')
@section('subtitle', 'One-time invoices and subscription renewals, with verified payment status.')
@section('actions')<a href="{{ route('billing.export') }}" class="btn btn-secondary">Export CSV</a>@endsection
@section('content')
<form class="flex gap-3 mb-5" method="GET"><select name="status" aria-label="Invoice status" class="input max-w-xs">@foreach([''=>'All invoices','outstanding'=>'Outstanding','overdue'=>'Overdue','paid'=>'Paid','void'=>'Void'] as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select><button class="btn btn-secondary">Filter</button></form>
@include('billing.invoice-table')
<details class="card p-6 mt-8" @if($errors->any()) open @endif><summary class="font-semibold cursor-pointer">Create a one-time invoice</summary>
@if($customers->isEmpty())<p class="mt-5">Add a customer first.</p><a href="{{ route('billing.customers') }}" class="btn btn-primary mt-3">Add customer</a>
@else<form method="POST" action="{{ route('billing.invoices.store') }}" class="mt-5 max-w-2xl">@csrf
<label class="label" for="customer">Customer</label><select id="customer" name="customer_id" class="input mb-5" required><option value="">Choose customer</option>@foreach($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id')==$c->id)>{{ $c->name }}</option>@endforeach</select>
<x-input name="description" label="Description" maxlength="255" required/><div class="grid sm:grid-cols-2 gap-4"><x-input name="amount" label="Amount (INR)" type="number" min="1" max="100000" step="0.01" required/><x-input name="discount" label="Discount (INR)" type="number" min="0" step="0.01" value="0"/></div>
<x-input name="due_on" label="Payment due date" type="date" :value="today('Asia/Kolkata')->addDays(7)->toDateString()" :min="today('Asia/Kolkata')->toDateString()" required/>
<button class="btn btn-primary">Create invoice</button></form>@endif</details>
@endsection
