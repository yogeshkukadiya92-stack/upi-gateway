@extends('layouts.app')
@section('title', 'Payment reminders')
@section('subtitle', 'Invoices due within three days or overdue. Copy a draft and send it yourself; no paid messaging service is connected.')
@section('content')
<div class="space-y-4">
@forelse($invoices as $invoice)
@php($message = 'Hello '.$invoice->customer_name.', your invoice '.$invoice->number.' for ₹'.number_format($invoice->totalPaise()/100,2).' is due on '.$invoice->due_on->format('d M Y').'. Please review and pay here: '.$invoice->publicUrl().'. If already paid, submit your UTR on the payment page. Thank you.')
<div class="card p-6"><div class="flex flex-wrap justify-between gap-3"><div><h2 class="font-semibold">{{ $invoice->customer_name }} · {{ $invoice->number }}</h2><p class="text-sm text-slate-500">₹{{ number_format($invoice->totalPaise()/100,2) }} · Due {{ $invoice->due_on->format('d M Y') }} · {{ ucfirst($invoice->state()) }}</p></div><a class="btn btn-secondary" href="{{ route('billing.invoice', $invoice) }}">View invoice</a></div>
@if($invoice->state()==='review')<p class="text-sm text-sky-700 mt-4">Customer submitted a UTR. Review the bank payment before sending a reminder.</p>
@else<p class="bg-slate-50 rounded-lg p-4 text-sm mt-4 break-words">{{ $message }}</p><div class="flex flex-wrap gap-2 mt-4"><button class="btn btn-secondary" data-copy="{{ $message }}">Copy reminder</button>
@if($invoice->customer->mobile)<a class="btn btn-secondary" target="_blank" rel="noopener noreferrer" href="https://wa.me/{{ preg_replace('/\D/','',$invoice->customer->mobile) }}?text={{ rawurlencode($message) }}">Open WhatsApp draft</a>@endif
@if($invoice->customer->email)<a class="btn btn-secondary" href="mailto:{{ rawurlencode($invoice->customer->email) }}?subject={{ rawurlencode('Invoice '.$invoice->number) }}&body={{ rawurlencode($message) }}">Open email draft</a>@endif
<form method="POST" action="{{ route('billing.invoice.reminder', $invoice) }}">@csrf<button class="btn btn-secondary">I sent this reminder</button></form></div>@endif
<p class="text-xs text-slate-400 mt-4">Recorded reminders: {{ $invoice->reminder_count }} · Last recorded: {{ $invoice->last_reminded_at?->timezone('Asia/Kolkata')->format('d M Y, h:i A') ?? 'Never' }}</p></div>
@empty<div class="card p-10 text-center text-slate-500">No invoices need a reminder right now.</div>@endforelse
</div><div class="mt-4">{{ $invoices->links() }}</div>
@endsection
