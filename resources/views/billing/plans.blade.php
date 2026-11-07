@extends('layouts.app')
@section('title', 'Subscription plans')
@section('subtitle', 'Fixed INR prices. Existing subscriptions keep their original price when a plan is archived.')
@section('content')
<div class="grid lg:grid-cols-3 gap-6"><div class="lg:col-span-2 grid sm:grid-cols-2 gap-4 content-start">
@forelse($plans as $plan)
<div class="card p-6"><span class="text-xs text-slate-500">{{ $plan->is_active ? 'Available' : 'Archived' }}</span><h2 class="font-semibold text-lg mt-2">{{ $plan->name }}</h2><div class="text-3xl font-bold mt-4">₹{{ number_format($plan->amount_paise/100,2) }}</div><p class="text-sm text-slate-500">{{ $plan->intervalLabel() }} · Due {{ $plan->due_days }} days after issue</p><form class="mt-5" method="POST" action="{{ route('billing.plans.toggle', $plan) }}">@csrf<button class="btn btn-secondary">{{ $plan->is_active ? 'Archive plan' : 'Restore plan' }}</button></form></div>
@empty<div class="card p-8 sm:col-span-2 text-slate-500">Create a plan, then assign it to a customer under Subscriptions.</div>@endforelse
<div class="sm:col-span-2">{{ $plans->links() }}</div></div>
<form class="card p-6 h-fit" method="POST" action="{{ route('billing.plans.store') }}">@csrf<h2 class="font-semibold mb-5">Create plan</h2>
<x-input name="name" label="Plan name" maxlength="100" required/><x-input name="amount" label="Price (INR)" type="number" min="1" max="100000" step="0.01" required/>
<label class="label" for="interval">Billing frequency</label><select id="interval" name="interval_months" class="input mb-5">@foreach([1=>'Monthly',3=>'Quarterly',12=>'Yearly'] as $n=>$label)<option value="{{ $n }}" @selected(old('interval_months',1)==$n)>{{ $label }}</option>@endforeach</select>
<x-input name="due_days" label="Payment due after (days)" type="number" min="0" max="30" value="7" required/><button class="btn btn-primary">Create plan</button></form></div>
@endsection
