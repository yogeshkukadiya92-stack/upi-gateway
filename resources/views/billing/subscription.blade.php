@extends('layouts.app')
@section('title', $subscription->plan_name)
@section('subtitle', 'Subscription for '.$subscription->customer->name)
@section('back', route('billing.subscriptions'))
@section('content')
<div class="card p-6 mb-6"><div class="grid sm:grid-cols-3 gap-5"><div><p class="text-sm text-slate-500">Status</p><p class="font-semibold">{{ ucfirst($subscription->status) }}</p></div><div><p class="text-sm text-slate-500">Amount per invoice</p><p class="font-semibold">₹{{ number_format(($subscription->amount_paise-$subscription->discount_paise)/100,2) }} / {{ $subscription->interval_months }} month(s)</p></div><div><p class="text-sm text-slate-500">Next invoice</p><p class="font-semibold">{{ $subscription->status === 'active' ? $subscription->next_billing_on?->format('d M Y') : 'Not scheduled' }}</p></div></div>
<p class="text-sm text-slate-500 mt-5">Pausing skips future billing dates while paused. Resuming continues on the next scheduled date, without catch-up charges. Cancelling stops new invoices. Existing invoices remain payable.</p>
<div class="flex flex-wrap gap-3 mt-5">@foreach(($subscription->status==='active' ? ['pause'=>'Pause invoices','cancel'=>'Cancel subscription'] : ($subscription->status==='paused' ? ['resume'=>'Resume invoices','cancel'=>'Cancel subscription'] : [])) as $action=>$label)
<form method="POST" action="{{ route('billing.subscription.change', $subscription) }}">@csrf<input type="hidden" name="action" value="{{ $action }}"><button class="btn btn-secondary" data-confirm="{{ $label }}? Existing invoices will stay unchanged.">{{ $label }}</button></form>@endforeach</div></div>
<h2 class="font-semibold mb-3">Invoice history</h2>@include('billing.invoice-table')
@endsection
