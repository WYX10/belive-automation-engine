-- The single/middle/master trio from 014 was BeLive's own vocabulary, but the
-- real listing book prices studio, balcony, partitioned and mini-single units
-- as distinct products. Collapsing them into the trio would make Eve quote a
-- RM2200 Mont Kiara studio as a "master" — so the enum widens to match the
-- inventory rather than the inventory bending to the enum.
--
-- Modifier variants (With Big Window, 2nd Single Bedroom, Single Private) stay
-- out of the enum: they are unit-level detail, carried in rooms.name and
-- room_amenities, not a different product type.

ALTER TABLE rooms MODIFY room_type ENUM(
    'single',
    'middle',
    'master',
    'studio',
    'balcony',
    'partitioned',
    'mini_single'
) NOT NULL;
