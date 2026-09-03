-- Adds date of birth. Run once in phpMyAdmin if the tables already exist;
-- setup.php creates it on a fresh install.
--
-- age is kept alongside it rather than derived on every read: it is the age ON
-- RACE DAY, which is a fixed fact about the entry, and storing it means the
-- kit desk never has to recompute anything from a birth date.

ALTER TABLE registrations
  ADD COLUMN dob DATE DEFAULT NULL AFTER age;
