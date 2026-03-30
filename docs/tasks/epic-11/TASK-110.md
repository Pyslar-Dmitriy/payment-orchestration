### TASK-110 — Design callback subscriptions and delivery history model

#### Tables
- `merchant_callback_subscriptions`
- `merchant_callback_deliveries`
- `merchant_callback_attempts`

#### Data to store
- merchant ID;
- callback URL;
- secret/signature config;
- event types;
- delivery status;
- retry count.

#### Done criteria
- delivery history can be traced;
- it is possible to understand why a callback failed.