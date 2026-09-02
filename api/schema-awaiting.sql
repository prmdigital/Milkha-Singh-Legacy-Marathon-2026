-- Adds the 'awaiting' status: registration received, fee to be collected by
-- the team. Run once in phpMyAdmin AFTER schema.sql.
--
-- 'awaiting' is deliberately separate from 'pending'. They look similar but
-- mean opposite things operationally:
--   pending  = the runner reached the payment gateway and did not finish.
--              Nobody should chase them; the webhook may still confirm it.
--   awaiting = the runner registered while online payment was switched off.
--              Somebody MUST ring them to take the money.
-- Merging the two would hide real work behind abandoned checkouts.

ALTER TABLE registrations
  MODIFY COLUMN status
  ENUM('pending','awaiting','paid','free','failed') NOT NULL DEFAULT 'pending';
