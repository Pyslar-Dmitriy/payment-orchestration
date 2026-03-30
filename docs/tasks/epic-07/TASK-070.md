### TASK-070 — Design the provider abstraction layer

#### What to do
Introduce a provider interface with a unified contract for:
- authorize
- capture
- refund
- parse webhook
- map status

#### Done criteria
- adding a new provider is easy;
- business logic is not scattered across adapters;
- payment-domain and orchestrator do not know PSP API details.