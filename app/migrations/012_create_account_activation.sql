-- Account activation.
--
-- A separate concept from `status` (which gates login/suspension) and
-- from `is_member` (the optional paid perk tier added in a later
-- migration) - see App\AccountActivation for what this actually gates:
-- checkout, not login and not browsing.
--
-- A new registration starts 'pending'. It flips to 'active' the moment
-- a wallet credit brings the balance to the configured minimum
-- (App\AccountActivation::maybeActivate(), called from Wallet::credit())
-- - never by a direct write outside that path, and never by consuming
-- any of the balance itself. The deposit that activates the account
-- stays ordinary, fully spendable balance.
ALTER TABLE users
    ADD COLUMN account_status ENUM('pending','active') NOT NULL DEFAULT 'pending' AFTER status;

-- Every row that existed before this column did is grandfathered in as
-- active. This is a new policy applying to registrations from here on,
-- not a retroactive lockout of accounts that were already trusted.
UPDATE users SET account_status = 'active';

ALTER TABLE users
    ADD KEY idx_users_account_status (account_status);
