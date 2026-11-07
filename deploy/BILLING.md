# Fee-free billing

Billing lives at `/billing`. It does not connect to Razorpay or any debit provider.

1. Add an active **Any UPI ID (manual verify)** account.
2. Add a customer, then a monthly, quarterly or yearly plan.
3. Create a subscription with a start date, optional fixed discount and optional cycle count.
4. The hourly `billing:renew` task generates invoices. The existing every-minute `artisan schedule:run` task must continue running.
5. Share each invoice's private link. Customers approve each UPI transfer themselves and submit a UTR. Review the bank/UPI record through the existing order screen before approval.

Invoices never charge customers automatically. Plan prices are copied into subscriptions so archiving a plan cannot change an agreed price. Paused billing dates are skipped; resuming does not back-bill skipped dates. Cancelled subscriptions cannot resume. Already issued invoices remain payable.

Invoice print view can be saved as PDF using the browser. These are billing records, not GST tax invoices. The CSV report includes verified payments and manual refund records. Refund recording does not transfer money. Reminder buttons create drafts; no message is sent by the app.

Customer links contain a random bearer token and should only be shared with the intended customer. Customer email, mobile and internal notes are not displayed publicly. Each invoice uses one payment order, including after expiry, to avoid duplicate charges being treated as separate invoices.

## Deployment

Use Railpack, build `npm run build`, start `sh deploy/start.sh`, port 3000. Remove legacy `RAILPACK_BUILD_CMD` and `RAILPACK_START_CMD` environment overrides. Keep the existing `APP_KEY`, database credentials and `/app/storage` volume. Railpack's PHP startup script runs migrations. The additive migration creates five billing tables without altering payment records.

For rollback, return to the previous image/commit and leave billing tables in place. Do not run migration rollback against live billing records.

## Checks

`php artisan test` covers tenant isolation, public-data minimisation, recurrence, duplicate generation, exact money, payment reuse and verification, refunds, CSV formula escaping and existing payment flows. `npm run build` builds the assets. Payment-provider fees are not introduced; normal hosting/bank/account terms still apply.
