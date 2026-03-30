### TASK-052 — Implement the 'create payment' use case

#### What to do
Create an application use case that:
- accepts the command;
- creates the payment;
- creates a payment attempt;
- writes payment history;
- writes an outbox event;
- returns a DTO.

#### Done criteria
- the whole operation is transactional;
- the outbox record is saved in the same transaction;
- duplicate requests are handled correctly at the upper layer.