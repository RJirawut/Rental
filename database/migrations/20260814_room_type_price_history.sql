-- Run once on an existing installation after deploying the dated-price feature.
-- Adds only room types with no history yet. Existing history and bills are untouched.
INSERT INTO room_type_price_history
    (room_type_id, effective_date, price_daily, price_monthly)
SELECT
    rt.id,
    CURDATE(),
    rt.price_daily,
    rt.price_monthly
FROM room_types AS rt
WHERE NOT EXISTS (
    SELECT 1
    FROM room_type_price_history AS ph
    WHERE ph.room_type_id = rt.id
);
