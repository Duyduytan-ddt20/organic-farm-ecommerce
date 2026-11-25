# Orders and checkout API

Base URL: `/api/v1`; send and accept JSON.

## Create a pending order

`POST /orders` is the customer checkout endpoint. It validates that every product exists and is active, verifies available unexpired stock, saves the order and its requested `order_items`, and leaves inventory unchanged until Sales confirms it.

```json
{
  "customer_name": "Nguyen Van A",
  "customer_email": "a@example.com",
  "customer_phone": "0900000000",
  "shipping_address": "1 Farm Road",
  "items": [
    { "product_id": 1, "quantity": 10 }
  ]
}
```

Returns `201` with a `pending` order and item price snapshots. Invalid product/quantity data returns `422`; insufficient valid stock returns `409` and does not create an order.

## Sales confirmation and FEFO allocation

Authenticated Sales or Admin users call `POST /sales/orders/{order}/confirm`. The service locks the order and all eligible product batches, orders batches by `expiration_date` ascending, excludes expired/empty lots, writes one `order_details` allocation per used lot, decrements that batch's stock, and marks the order confirmed. The allocation, stock update, and status update run in one transaction. Repeating confirmation for an already confirmed order is idempotent. If any item is short at confirmation, all allocations and decrements from that attempt roll back and the order stays pending (`409`).

`GET /sales/orders` lists orders; `GET /sales/orders/{order}` returns item and allocation detail. Both routes require an authenticated Sales or Admin user.
