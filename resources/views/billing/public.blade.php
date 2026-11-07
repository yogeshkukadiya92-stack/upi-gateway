@extends('layouts.base')
@section('title', $invoice->number)
@section('body')
<main class="max-w-2xl mx-auto px-4 py-10">
<div class="card p-6 sm:p-10"><div class="flex flex-wrap justify-between gap-4"><div><p class="text-xs uppercase tracking-wider text-slate-500">Invoice</p><h1 class="font-bold text-2xl mt-2">{{ $invoice->merchant_name }}</h1><p class="text-sm text-slate-500 mt-2">{{ $invoice->number }}</p></div><div class="text-sm"><span class="rounded-full bg-slate-100 px-3 py-1 font-semibold">{{ ucfirst($invoice->state()) }}</span></div></div>
<div class="grid sm:grid-cols-2 gap-5 my-8 text-sm"><div><p class="text-slate-500">Billed to</p><p class="font-semibold">{{ $invoice->customer_name }}</p></div><div><p>Issued: {{ $invoice->issued_on->format('d M Y') }}</p><p>Due: {{ $invoice->due_on->format('d M Y') }}</p></div></div>
<div class="border-y py-5"><h2 class="font-semibold">{{ $invoice->description }}</h2><div class="flex justify-between mt-5"><span>Amount</span><span>₹{{ number_format($invoice->amount_paise/100,2) }}</span></div>@if($invoice->discount_paise)<div class="flex justify-between mt-2"><span>Discount</span><span>− ₹{{ number_format($invoice->discount_paise/100,2) }}</span></div>@endif</div>
<div class="flex justify-between font-bold text-2xl my-6"><span>Total</span><span>₹{{ number_format($invoice->totalPaise()/100,2) }}</span></div>
@if($errors->any())<div role="alert" class="bg-red-50 text-red-700 p-4 rounded-lg my-4">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($invoice->state()==='paid')<div class="bg-emerald-50 text-emerald-800 rounded-lg p-4"><h2 class="font-semibold">Payment received</h2><p class="text-sm">Confirmed {{ $invoice->order->paid_at?->timezone('Asia/Kolkata')->format('d M Y, h:i A') }}. Thank you.</p></div>
@elseif($invoice->state()==='void')<p class="text-slate-600">This invoice has been voided. No payment is required.</p>
@elseif($invoice->state()==='review')<p class="bg-sky-50 text-sky-800 p-4 rounded-lg">Your payment reference is being reviewed. Please do not pay again.</p>
@else<form class="print:hidden" method="POST" action="{{ route('billing.pay', $invoice->public_token) }}">@csrf<button class="btn btn-primary w-full">Pay ₹{{ number_format($invoice->totalPaise()/100,2) }} with UPI</button></form><p class="text-sm text-slate-500 mt-4">You approve this payment in your UPI app. This is not AutoPay and does not authorise future debits. After paying, submit the UTR for the merchant to verify.</p>@endif
<button type="button" class="btn btn-secondary mt-6 print:hidden" data-print-page>Print / save PDF</button>
<p class="text-xs text-slate-400 mt-6">INR billing record. Not a GST tax invoice. For billing corrections or refunds, contact the merchant directly.</p></div>
</main>
@endsection
