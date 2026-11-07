@extends('layouts.app')
@section('title', 'Subscriptions')
@section('subtitle', 'Scheduled invoices, without automatic debits. Customers approve each payment.')
@section('actions')<form method="POST" action="{{ route('billing.generate') }}">@csrf<button class="btn btn-secondary">Generate due invoices</button></form>@endsection
@section('content')
<div class="grid lg:grid-cols-3 gap-6"><div class="lg:col-span-2"><div class="card divide-y divide-slate-100">
@forelse($subscriptions as $sub)
<a href="{{ route('billing.subscription', $sub) }}" class="block p-5 hover:bg-slate-50"><div class="flex justify-between gap-3"><div><h2 class="font-semibold">{{ $sub->customer->name }}</h2><p class="text-sm text-slate-500">{{ $sub->plan_name }} · ₹{{ number_format(($sub->amount_paise-$sub->discount_paise)/100,2) }} every {{ $sub->interval_months }} month(s)</p></div><span class="text-sm font-semibold">{{ ucfirst($sub->status) }}</span></div><p class="text-xs text-slate-500 mt-2">Next invoice: {{ $sub->status === 'active' ? $sub->next_billing_on?->format('d M Y') : 'Not scheduled' }}</p></a>
@empty<div class="p-10 text-center text-slate-500">No subscriptions yet.</div>@endforelse
</div><div class="mt-4">{{ $subscriptions->links() }}</div></div>
<div class="card p-6 h-fit"><h2 class="font-semibold mb-5">New subscription</h2>
@if($customers->isEmpty() || $plans->isEmpty())<p class="text-sm text-slate-500 mb-4">Add an active customer and plan first.</p><div class="flex gap-2"><a href="{{ route('billing.customers') }}" class="btn btn-secondary">Customers</a><a href="{{ route('billing.plans') }}" class="btn btn-secondary">Plans</a></div>
@else<form method="POST" action="{{ route('billing.subscriptions.store') }}">@csrf
<label class="label" for="customer">Customer</label><select id="customer" name="customer_id" class="input mb-5" required><option value="">Choose customer</option>@foreach($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id')==$c->id)>{{ $c->name }}</option>@endforeach</select>
<label class="label" for="plan">Plan</label><select id="plan" name="plan_id" class="input mb-5" required><option value="">Choose plan</option>@foreach($plans as $p)<option value="{{ $p->id }}" @selected(old('plan_id')==$p->id)>{{ $p->name }} · ₹{{ number_format($p->amount_paise/100,2) }} · {{ $p->intervalLabel() }}</option>@endforeach</select>
<x-input name="starts_on" label="First invoice date" type="date" :value="today('Asia/Kolkata')->toDateString()" :min="today('Asia/Kolkata')->toDateString()" required/>
<x-input name="discount" label="Discount per invoice (INR)" type="number" step="0.01" min="0" value="0"/>
<x-input name="total_cycles" label="Number of cycles (optional)" type="number" min="1" max="1200" help="Leave blank to continue until cancelled. Paused cycles are skipped."/>
<p class="text-xs text-slate-500 mb-4">Confirm the plan and billing terms with your customer before sharing invoices. This does not create an AutoPay mandate.</p><button class="btn btn-primary">Create subscription</button></form>@endif</div></div>
@endsection
