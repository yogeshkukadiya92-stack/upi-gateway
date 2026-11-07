<x-input name="name" label="Customer name" :value="$customer->name ?? null" maxlength="100" required />
<x-input name="email" label="Email (optional)" type="email" :value="$customer->email ?? null" maxlength="255" />
<x-input name="mobile" label="Mobile (optional)" :value="$customer->mobile ?? null" placeholder="+919876543210" help="Include country code for WhatsApp." maxlength="16" />
<label for="notes" class="label">Internal notes (optional)</label><textarea id="notes" name="notes" class="input mb-5" maxlength="2000">{{ old('notes', $customer->notes ?? '') }}</textarea>
