@extends('layouts.app')
@section('title', $subscription->plan_name)
@section('subtitle', 'Subscription for '.$subscription->customer->name)
@section('back', route('billing.subscriptions'))
@section('content')
<div class="card p-6 mb-6"><div class="grid sm:grid-cols-3 gap-5"><div><p class="text-sm text-slate-500">Status</p><p class="font-semibold">{{ ucfirst($subscription->status) }}</p></div><div><p class="text-sm text-slate-500">Amount per invoice</p><p class="font-semibold">₹{{ number_format(($subscription->amount_paise-$subscription->discount_paise)/100,2) }} / {{ $subscription->interval_months }} month(s)</p></div><div><p class="text-sm text-slate-500">Next invoice</p><p class="font-semibold">{{ $subscription->status === 'active' ? $subscription->next_billing_on?->format('d M Y') : 'Not scheduled' }}</p></div></div>
<p class="text-sm text-slate-500 mt-5">Pausing skips future billing dates while paused. Resuming continues on the next scheduled date, without catch-up charges. Cancelling stops new invoices. Existing invoices remain payable.</p>
<div class="flex flex-wrap gap-3 mt-5">@foreach(($subscription->status==='active' ? ['pause'=>'Pause invoices','cancel'=>'Cancel subscription'] : ($subscription->status==='paused' ? ['resume'=>'Resume invoices','cancel'=>'Cancel subscription'] : [])) as $action=>$label)
<form method="POST" action="{{ route('billing.subscription.change', $subscription) }}">@csrf<input type="hidden" name="action" value="{{ $action }}"><button class="btn btn-secondary" data-confirm="{{ $label }}? Existing invoices will stay unchanged.">{{ $label }}</button></form>@endforeach</div></div>
<div class="card p-6 mb-6">
<h2 class="font-semibold">Trial and plan changes</h2>
<p class="text-sm mt-2">Trial: {{ $subscription->trial_days }} day(s). First billing date: {{ $subscription->starts_on->addDays($subscription->trial_days)->format('d M Y') }}.</p>
@if($subscription->pending_plan)
<p class="mt-3">Scheduled: {{ $subscription->pending_plan['plan_name'] }} · ₹{{ number_format($subscription->pending_plan['amount_paise']/100,2) }} every {{ $subscription->pending_plan['interval_months'] }} month(s), from the next generated invoice.</p>
<form method="POST" action="{{ route('billing.subscription.plan.cancel',$subscription) }}" class="mt-3">@csrf @method('DELETE')<button class="btn btn-secondary">Remove scheduled change</button></form>
@endif
@if(in_array($subscription->status,['active','paused']))
<form method="POST" action="{{ route('billing.subscription.plan',$subscription) }}" class="mt-5">@csrf
<label class="label" for="new-plan">Upgrade / downgrade plan</label><select id="new-plan" name="plan_id" class="input" required>@foreach($plans as $plan)<option value="{{ $plan->id }}">{{ $plan->name }} · ₹{{ number_format($plan->amount_paise/100,2) }} / {{ $plan->interval_months }} month(s)</option>@endforeach</select>
<p class="text-sm text-slate-500 my-3">Changes start with the next invoice. Existing invoices stay unchanged. No mid-cycle charge or credit. Existing recurring discounts end on the new plan. Confirm the new terms with your customer.</p><button class="btn btn-primary">Schedule plan change</button></form>@endif</div>
<h2 class="font-semibold mb-3">Invoice history</h2>@include('billing.invoice-table')
@endsection
