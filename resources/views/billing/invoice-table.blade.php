<div class="card overflow-x-auto">
<table class="w-full text-sm text-left">
    <thead class="bg-slate-50 text-slate-500"><tr><th class="p-4">Invoice / customer</th><th class="p-4">Due date</th><th class="p-4">Amount</th><th class="p-4">Status</th><th class="p-4">Details</th></tr></thead>
    <tbody class="divide-y divide-slate-100">
    @forelse ($invoices as $invoice)
        <tr><td class="p-4"><div class="font-semibold">{{ $invoice->number }}</div><div class="text-slate-500">{{ $invoice->customer_name }}</div></td>
            <td class="p-4">{{ $invoice->due_on->format('d M Y') }}</td><td class="p-4 font-semibold">₹{{ number_format($invoice->totalPaise()/100, 2) }}<div class="text-xs text-slate-500">₹{{ number_format($invoice->remainingPaise()/100,2) }} remaining</div></td>
            <td class="p-4"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{{ ucfirst($invoice->state()) }}</span></td>
            <td class="p-4"><a class="btn btn-secondary" href="{{ route('billing.invoice', $invoice) }}">View invoice</a></td></tr>
    @empty
        <tr><td colspan="5" class="p-10 text-center text-slate-500">No invoices yet. Create a subscription or a one-time invoice.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@if ($invoices instanceof \Illuminate\Contracts\Pagination\Paginator)<div class="mt-4">{{ $invoices->links() }}</div>@endif
