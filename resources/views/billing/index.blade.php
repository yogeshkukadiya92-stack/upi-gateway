@extends('layouts.app')
@section('title', 'Billing overview')
@section('subtitle', 'Recurring invoices and direct UPI payments, without a payment-provider integration.')
@section('actions')<a href="{{ route('billing.subscriptions') }}" class="btn btn-primary">New subscription</a>@endsection
@section('content')
<div class="card p-5 mb-6 bg-sky-50 text-sky-900">
    <h2 class="font-semibold">Automatic invoices. Customer-approved payments.</h2>
    <p class="text-sm mt-1">Renewal invoices are generated on schedule. Share the invoice link; customers pay with UPI each time. No automatic debit, paid messaging service or provider API is used.</p>
</div>
@if (!$hasUpi)<div class="card p-5 mb-6"><p class="font-semibold">Set up your receiving UPI ID</p><p class="text-sm text-slate-500 mt-1">Choose “Any UPI ID (manual verify)” and confirm payments against your bank or UPI app.</p><a href="{{ route('accounts.create') }}" class="btn btn-secondary mt-3">Add UPI account</a></div>@endif
<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    @foreach ([['Active customers', number_format($customers)], ['Active subscriptions', number_format($active)], ['Outstanding invoices', '₹'.number_format($outstanding/100,2)], ['Collected less recorded refunds', '₹'.number_format(($collected-$refunds)/100,2)]] as [$label,$value])
    <div class="card p-5"><div class="text-sm text-slate-500">{{ $label }}</div><div class="text-2xl font-bold mt-2">{{ $value }}</div></div>
    @endforeach
</div>
<div class="grid md:grid-cols-3 gap-4 mb-8">
    <a href="{{ route('billing.customers') }}" class="card p-5"><h2 class="font-semibold">1. Add customers</h2><p class="text-sm text-slate-500 mt-1">Save contact details and internal notes.</p></a>
    <a href="{{ route('billing.plans') }}" class="card p-5"><h2 class="font-semibold">2. Create plans</h2><p class="text-sm text-slate-500 mt-1">Monthly, quarterly or yearly billing.</p></a>
    <a href="{{ route('billing.reminders') }}" class="card p-5"><h2 class="font-semibold">3. Follow up</h2><p class="text-sm text-slate-500 mt-1">Copy reminder text and record your follow-ups.</p></a>
</div>
<div class="flex items-center justify-between mb-3"><h2 class="font-semibold">Recent invoices</h2><a class="text-brand-700" href="{{ route('billing.invoices') }}">View all</a></div>
@include('billing.invoice-table')
<div class="card p-5 mt-8"><h2 class="font-semibold mb-3">Export billing report</h2><p class="text-sm text-slate-500 mb-3">CSV includes invoice amounts, discounts, payment references and recorded refunds. Date filters use the invoice issue date.</p>
<form method="GET" action="{{ route('billing.export') }}" class="flex flex-wrap items-end gap-4"><x-input name="from" label="From (optional)" type="date"/><x-input name="to" label="To (optional)" type="date"/><button class="btn btn-secondary mb-5">Download CSV</button></form></div>
@endsection
