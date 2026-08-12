-- Remove the separate "Billions Membership" tier. The account-activation
-- gate (migration 012, App\AccountActivation) is unaffected and stays -
-- this only removes the optional paid-upgrade concept that used to sit
-- alongside it: member pricing, members-only categories, and the
-- membership_payments record of who paid the membership fee.
--
-- Existing member_price_minor values are simply dropped along with the
-- column - a product's price_minor (the standard price) is untouched,
-- so nothing here changes what anyone already paid or what anything
-- currently costs to a non-member.

ALTER TABLE product_orders
    DROP COLUMN was_member_price;

ALTER TABLE products
    DROP COLUMN member_price_minor,
    DROP CONSTRAINT chk_products_member_price;

ALTER TABLE product_categories
    DROP COLUMN is_members_only;

DROP TABLE membership_payments;

ALTER TABLE users
    DROP COLUMN is_member,
    DROP COLUMN member_since;
