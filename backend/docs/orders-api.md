# Orders and checkout API

Base URL: `/api/v1`; use JSON. Checkout requires a Sanctum-authenticated Customer or Admin. Sales/Admin endpoints also require the corresponding role in the user's `roles` record.

## Customer registration and login

`POST /auth/register` creates an active Customer account from `full_name`, `email`, optional `phone`, and a confirmed password of at least eight characters. It returns `201` and a Sanctum bearer token.
`POST /auth/login` accepts an email and password and returns a Sanctum bearer token. Send it on protected requests as `Authorization: Bearer <token>`.
`POST /auth/logout` revokes the current bearer token.

The local database seeder creates a demo customer (`customer@organicfarm.vn` / `12345678`) and a delivered demo order. In the Vite development app, `/orders/preview` opens that real seeded order after login so the claim upload can be tried end to end. Its API endpoint is available only in local/testing environments.

## Claim attachments

`PUT /claims/{claim}` updates the reason, description, claimed quantity, and optionally appends new images. Only the order owner or an Admin can edit a pending claim; the order item and claim status remain unchanged. The claim may have up to five images total.
`DELETE /claims/{claim}/attachments/{attachment}` removes an image from both public Storage and `claim_attachments`. Only the order owner or an Admin can delete it, and only while the claim is pending.

## Create a pending order

`POST /orders` validates active products and available non-expired batches, then stores an order and requested `order_items`. Stock is not changed during checkout; it is checked again and allocated only when Sales confirms the order.

```json
{
  "recipient_name": "Nguyen Van A",
  "recipient_phone": "0900000000",
  "delivery_address": "1 Farm Road",
  "items": [
    { "product_id": 2, "quantity": 10 }
  ]
}
```

Returns `201` with status `pending`. Invalid input returns `422`; insufficient valid stock returns `409` without creating the order.

## Sales confirmation and FEFO allocation

`POST /sales/orders/{order}/confirm` requires an authenticated Sales or Admin user. The service locks the order and eligible batches, orders batches by `expires_at` ascending, excludes expired, unavailable and empty batches, writes one `order_details` row per allocated batch, decrements `available_quantity`, and marks the order confirmed. Allocation, stock changes and order status are committed in one database transaction. Repeating confirmation for a confirmed order is idempotent. If any product is short at confirmation, the transaction rolls back all allocations and stock changes and the order remains pending (`409`).

`GET /sales/orders` and `GET /sales/orders/{order}` list orders and return their item allocations. Both routes require Sales or Admin access.
