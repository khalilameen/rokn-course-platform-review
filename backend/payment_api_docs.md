# Kashier Payment Integration — Mobile API Documentation

> **Version:** 1.0 · **Updated:** 2026-06-30  
> **Base URL:** `https://your-domain.com` *(replace with production URL)*  
> **Environment:** `test` mode active — use test cards listed at the bottom

---

## Table of Contents

1. [Authentication](#1-authentication)
2. [List Packages](#2-list-packages)
3. [Initiate Payment](#3-initiate-payment)
4. [Check Payment Status](#4-check-payment-status)
5. [WebView Payment Flow](#5-webview-payment-flow)
6. [Result Page — App Communication](#6-result-page--app-communication)
7. [Error Reference](#7-error-reference)
8. [Test Cards](#8-test-cards)
9. [Full Flow Diagram](#9-full-flow-diagram)

---

## 1. Authentication

All payment endpoints require a **Bearer token** in the `Authorization` header.

```
Authorization: Bearer {api_token}
Content-Type: application/json
Accept: application/json
```

> [!IMPORTANT]
> The token is obtained from the login response (`data.api_token`). There is **no** separate payment authentication step.

---

## 2. List Packages

Fetch all available coin packages to show the user before initiating payment.

### `GET /api/packages`

**Auth required:** No  
**Headers:** `Authorization: Bearer {token}` (optional, but include for consistency)

#### Success Response `200`

```json
{
  "status": true,
  "data": [
    {
      "id": 1,
      "name_ar": "باقة 500 عملة",
      "name_en": "500 Coins Package",
      "price": 150.00,
      "coins": 500,
      "created_at": "2026-06-01T10:00:00.000000Z",
      "updated_at": "2026-06-01T10:00:00.000000Z"
    },
    {
      "id": 2,
      "name_ar": "باقة 1200 عملة",
      "name_en": "1200 Coins Package",
      "price": 300.00,
      "coins": 1200,
      "created_at": "2026-06-01T10:00:00.000000Z",
      "updated_at": "2026-06-01T10:00:00.000000Z"
    }
  ]
}
```

| Field | Type | Description |
|---|---|---|
| `id` | integer | Package ID — send this when initiating payment |
| `name_ar` | string | Arabic package name |
| `name_en` | string | English package name |
| `price` | decimal | Price in **EGP** (Egyptian Pounds) |
| `coins` | integer | Number of coins credited on purchase |

---

## 3. Initiate Payment

Start a Kashier payment session for a package. Returns a URL to open in a WebView.

### `POST /api/payment/initiate`

**Auth required:** ✅ Yes  
**Content-Type:** `application/json`

#### Request Body

```json
{
  "package_id": 1
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `package_id` | integer | ✅ | ID of the package from `/api/packages` |

#### Success Response `200`

```json
{
  "success": true,
  "payment_url": "https://checkout.kashier.io?merchantId=MID-3552-454&orderId=PKG-6862...&mode=test&amount=150.00&currency=EGP&hash=a3f8...&merchantRedirect=https%3A%2F%2Fyour-domain.com%2Fpayment%2Fcallback&allowedMethods=card%2Cwallet%2Cbank_installments&display=en",
  "order_ref": "PKG-6862C1A3F0B24",
  "amount": "150.00",
  "package": {
    "id": 1,
    "name_ar": "باقة 500 عملة",
    "name_en": "500 Coins Package",
    "coins": 500
  }
}
```

| Field | Type | Description |
|---|---|---|
| `payment_url` | string | **Open this in a WebView** — full Kashier checkout URL |
| `order_ref` | string | Unique order reference — save it for status polling |
| `amount` | string | Amount in EGP |
| `package` | object | Package details |

> [!NOTE]
> If the user already has a **pending** (unpaid) order for the same package, the same `order_ref` and a freshly-signed `payment_url` are returned — no duplicate order is created.

#### Error Responses

| Status | Cause | Response |
|---|---|---|
| `401` | Missing or invalid token | `{"message": "Unauthenticated."}` |
| `422` | Validation failed | See example below |
| `500` | Server error creating order | `{"success": false, "message": "Failed to create payment order."}` |

**Validation error example (`422`):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "package_id": ["The selected package id is invalid."]
  }
}
```

---

## 4. Check Payment Status

Poll this after the WebView communicates a result. Returns the current state of the order.

### `GET /api/payment/status/{order_ref}`

**Auth required:** ✅ Yes

#### URL Parameter

| Parameter | Type | Description |
|---|---|---|
| `order_ref` | string | The `order_ref` returned by `/api/payment/initiate` |

#### Example Request

```
GET /api/payment/status/PKG-6862C1A3F0B24
Authorization: Bearer {api_token}
```

#### Success Response `200`

```json
{
  "success": true,
  "order_ref": "PKG-6862C1A3F0B24",
  "status": "approved",
  "transaction_id": "TXN-KSH-20260630-001",
  "amount": "150.00",
  "package": {
    "id": 1,
    "name_ar": "باقة 500 عملة",
    "name_en": "500 Coins Package",
    "coins": 500
  },
  "approved_at": "2026-06-30T19:45:12.000000Z"
}
```

#### `status` Values

| Value | Meaning | Action |
|---|---|---|
| `pending` | Payment not yet completed | Keep polling or wait for WebView signal |
| `approved` | ✅ Payment successful, coins credited | Show success screen, refresh wallet balance |
| `failed` | ❌ Payment failed or was rejected | Show failure screen, let user retry |

#### Error Responses

| Status | Cause |
|---|---|
| `401` | Unauthenticated |
| `404` | Order not found **or** belongs to a different user |

---

## 5. WebView Payment Flow

### Step-by-Step Integration

```
1. Call POST /api/payment/initiate  →  get payment_url + order_ref
2. Store order_ref locally
3. Open payment_url in a WebView (full-screen)
4. User completes payment on Kashier's page
5. Kashier redirects WebView to our result page
6. Result page fires:
     - rokn://payment-result?status=success&order_ref=PKG-xxx&coins=500
     - ReactNativeWebView.postMessage(JSON)        ← React Native
     - window.webkit.messageHandlers.paymentResult ← iOS WKWebView
7. Close WebView, call GET /api/payment/status/{order_ref} to confirm
8. Refresh user's wallet_coins balance
```

### WebView Configuration

Open `payment_url` in a full-screen WebView that:
- ✅ Allows JavaScript execution
- ✅ Allows redirects
- ✅ Handles the `rokn://` custom URL scheme
- ✅ Listens for `postMessage` events

> [!IMPORTANT]
> Do **not** intercept or block the redirect to `your-domain.com/payment/callback` — that is our server's callback page that processes the payment and renders the result.

---

## 6. Result Page — App Communication

After Kashier redirects back to our server, the result page automatically triggers **three communication methods simultaneously**. Implement whichever fits your stack:

### Method A — Deep Link (Universal)

The result page fires:

```
rokn://payment-result?status=success&order_ref=PKG-xxx&coins=500&transaction_id=TXN-001
rokn://payment-result?status=failed&order_ref=PKG-xxx&coins=0&transaction_id=
```

Register a handler for `rokn://payment-result` in your app and read the query parameters.

| Query Param | Values | Description |
|---|---|---|
| `status` | `success` / `failed` | Payment outcome |
| `order_ref` | string | The order reference |
| `coins` | integer | Coins credited (0 on failure) |
| `transaction_id` | string | Kashier transaction ID (empty on failure) |

### Method B — React Native WebView (`postMessage`)

```javascript
// In your React Native component:
<WebView
  onMessage={(event) => {
    const data = JSON.parse(event.nativeEvent.data);
    // data.type           === "payment_result"
    // data.status         === "success" | "failed"
    // data.order_ref      === "PKG-xxx"
    // data.coins_credited === 500
    // data.transaction_id === "TXN-001"

    if (data.type === 'payment_result') {
      navigation.goBack(); // close WebView
      if (data.status === 'success') {
        refreshWallet();
        showSuccessScreen(data);
      } else {
        showFailureScreen();
      }
    }
  }}
  source={{ uri: paymentUrl }}
/>
```

### Method C — iOS WKWebView (Swift/Obj-C)

```swift
// Register the handler:
webView.configuration.userContentController.add(self, name: "paymentResult")

// Receive the message:
func userContentController(_ userContentController: WKUserContentController,
                           didReceive message: WKScriptMessage) {
    if message.name == "paymentResult",
       let body = message.body as? String,
       let data = body.data(using: .utf8),
       let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
        let status = json["status"] as? String
        // handle result...
    }
}
```

### Message Payload Schema (all methods)

```json
{
  "type": "payment_result",
  "status": "success",
  "order_ref": "PKG-6862C1A3F0B24",
  "coins_credited": 500,
  "transaction_id": "TXN-KSH-20260630-001"
}
```

> [!NOTE]
> Always **confirm** the result via `GET /api/payment/status/{order_ref}` after receiving the WebView signal — this is the server-authoritative status and protects against any client-side tampering.

---

## 7. Error Reference

### HTTP Status Codes

| Code | Meaning |
|---|---|
| `200` | Success |
| `401` | Unauthenticated — invalid or missing Bearer token |
| `403` | Forbidden — e.g. invalid payment signature |
| `404` | Resource not found |
| `422` | Validation error — check `errors` field in response |
| `500` | Server error — contact backend team |

### Payment Status Values

| Status | Description |
|---|---|
| `pending` | Order created, awaiting user payment |
| `approved` | Payment confirmed, coins credited to wallet |
| `failed` | Payment was declined or cancelled |

---

## 8. Test Cards

> [!WARNING]
> The API is currently in **test mode** (`KASHIER_MODE=test`). All payments are simulated — no real money is charged.

| Card Number | Expiry | CVV | Result |
|---|---|---|---|
| `5111 1111 1111 1118` | `06/27` | `100` | ✅ Success |
| `5123 4500 0000 0008` | `06/27` | `100` | ✅ Success (3D Secure) |
| `5111 1111 1111 1118` | `05/20` | `102` | ❌ Failure |

> Use any name and billing address — they are not validated in test mode.

---

## 9. Full Flow Diagram

```
Mobile App                    Backend API                    Kashier
──────────────────────────────────────────────────────────────────────

1. POST /api/payment/initiate
   { package_id: 1 }    ────►  Create pending Order
                               Generate Kashier HPP URL
                         ◄────  { payment_url, order_ref }

2. Open WebView(payment_url) ──────────────────────────────► Kashier
                                                             Checkout
                                                             Page

3.                                                           User pays
                                                             with card

4. Kashier redirects WebView ◄──────────────────────────────
   to: /payment/callback?paymentStatus=SUCCESS&...

5.                              Validate HMAC signature
                                Update Order → approved
                                Credit user wallet_coins
                                Insert package_user pivot

6. WebView renders result page
   Auto-fires:
   rokn://payment-result?status=success&...
   postMessage({ type: "payment_result", status: "success" })

7. App closes WebView
   App handles deep link / postMessage

8. GET /api/payment/status/{order_ref}
                         ────►  Find Order by ref + user_id
                         ◄────  { status: "approved", coins: 500 }

9. Refresh wallet balance display  ✅  Done
```

---

## Quick Reference Card

```
Endpoint                           Method  Auth  Description
─────────────────────────────────────────────────────────────────────
/api/packages                      GET     No    List available packages
/api/payment/initiate              POST    Yes   Start payment → get URL
/api/payment/status/{order_ref}    GET     Yes   Poll payment result
/payment/callback                  GET     No    Kashier redirects here (WebView)
```

---

*For questions contact the backend team. Do not share or commit the Bearer token.*
