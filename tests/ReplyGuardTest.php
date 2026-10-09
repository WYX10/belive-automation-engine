<?php

declare(strict_types=1);

use App\AI\Skills\ReplyGuard;
use App\Core\Database;
use App\Models\Room;

$guardRoom = Room::create(['name' => 'Guard Room', 'room_code' => 'GUARD-TEST', 'location' => 'Guard Area', 'room_type' => 'single', 'deposit_amount' => 0, 'status' => 'available']);
Database::run("INSERT INTO room_pricing (room_id, tenure, price, is_best_value) VALUES (?, '12_month', 620, 1), (?, 'monthly', 720, 0)", [$guardRoom, $guardRoom]);
$inventory = [Room::find($guardRoom)];
check('inventory guard accepts the actual tenure price', ReplyGuard::priceError('RM620/mo for 12 months.', $inventory, []) === null);
check('inventory guard accepts a real tenure saving', ReplyGuard::priceError('Save RM100 with a 12-month stay.', $inventory, []) === null);
check('inventory guard rejects an invented price', ReplyGuard::priceError('This room costs RM9999 monthly.', $inventory, []) !== null);
check('inventory guard rejects prices with no inventory', ReplyGuard::priceError('Rental is RM650 monthly.', [], []) !== null);
check('tenant budget is context rather than a quoted rent', ReplyGuard::priceError('Your budget is RM800; let me check matches.', $inventory, ['budget' => 800]) === null);
check('tenant budget cannot be disguised as unverified rent', ReplyGuard::priceError('This room is RM800 monthly.', $inventory, ['budget' => 800]) !== null);
check('a real price cannot be quoted for the wrong tenure', ReplyGuard::priceError('RM720/mo for 12 months.', $inventory, []) !== null);
check('a saving cannot be presented as the rental price', ReplyGuard::priceError('This room is RM100 monthly.', $inventory, []) !== null);
check('valid tenure comparisons are checked against each labelled amount', ReplyGuard::priceError('RM720 monthly, or RM620/mo for 12 months.', $inventory, []) === null);
check('a preceding deposit does not change how rent is checked', ReplyGuard::priceError('Deposit RM0, rent RM720 monthly.', $inventory, []) === null);
