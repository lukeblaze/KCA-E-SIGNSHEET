<?php
declare(strict_types=1);
// Timetable planner tests: drives Kca\Timetable directly against a throwaway SQLite file and the mock portal.
// Usage: php tests/timetable.php
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/kca-tt-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
putenv('APP_ENV=dev'); putenv('APP_SECRET=' . bin2hex(random_bytes(32))); putenv("STORAGE_DIR=$tmp"); putenv('PORTAL_DRIVER=mock');
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });
require $root . '/src/bootstrap.php';

use Kca\{Audit, Db, HttpError, Timetable};
use Kca\Portal\MockPortal;

$pass = 0; $fail = 0;
function ok(bool $c, string $name, string $extra = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name $extra\n"; } }
/** Runs $f and returns [status, message] of the HttpError it throws, or [0, ''] if it does not. */
function fails(callable $f): array { try { $f(); return [0, '']; } catch (HttpError $e) { return [$e->status, $e->getMessage()]; } }

$n = 0;
/** @return array{0:Timetable,1:PDO,2:Audit} a timetable service over a brand-new database */
function fresh(): array
{
    global $tmp, $n;
    putenv('DB_PATH=' . $tmp . '/t' . (++$n) . '.sqlite');
    $db = Db::connect();
    $audit = new Audit($db);
    return [new Timetable($db, new MockPortal(), $audit), $db, $audit];
}

$DIR = ['email' => 'director@kcau.ac.ke', 'name' => 'Exam Director', 'role' => 'exam_director', 'school' => 'Examinations Office'];
$TECH = ['email' => 'coordinator@kcau.ac.ke', 'name' => 'Tech Coordinator', 'role' => 'coordinator', 'school' => 'School of Technology'];
$TECH2 = ['email' => 'coord2@kcau.ac.ke', 'name' => 'Second Tech Coordinator', 'role' => 'coordinator', 'school' => 'School of Technology'];
$BIZ = ['email' => 'biz.coord@kcau.ac.ke', 'name' => 'Business Coordinator', 'role' => 'coordinator', 'school' => 'School of Business'];
$INV = ['email' => 'invigilator1@kcau.ac.ke', 'name' => 'Invigilator One', 'role' => 'invigilator', 'school' => 'School of Technology'];
$H = 'Unit,Date,Start,End,Venue,Lecturer,Candidates';
$P = ['period' => 'Sem 2 2026, Main exams'];
function pv(Timetable $t, array $u, string $csv, array $extra = []): array { global $P; return $t->preview($u, array_merge($P, ['csv' => $csv], $extra))['import']; }
function sittings(array $imp): array { return $imp['plan']['sessions']; }
function errTexts(array $imp): array { return array_map(fn($e) => $e['msg'], $imp['plan']['errors']); }
function people(array $s): array { return array_values(array_filter(array_merge([$s['chief']], $s['invs']))); }

// ------------------------------------------------------------------------------------------------
echo "Access\n";
[$t, $db] = fresh();
[$c, $m] = fails(fn() => $t->index($INV));
ok($c === 403 && $m === 'Only the exam director or a school exam coordinator can manage the timetable.', 'invigilator cannot open the timetable', "$c $m");
[$c] = fails(fn() => $t->preview($INV, $P + ['csv' => "$H\n"])); ok($c === 403, 'invigilator cannot preview');
[$c] = fails(fn() => $t->approve($INV, 1)); ok($c === 403, 'invigilator cannot approve');
$ix = $t->index($DIR);
ok($ix['school'] === 'Examinations Office' && $ix['ratio'] === 40 && $ix['imports'] === [] && $ix['role'] === 'exam_director' && count($ix['schools']) === 3, 'index shape for the director');
ok(in_array('supervisor@kcau.ac.ke', array_column($ix['chiefs'], 'email'), true) && !in_array('invigilator1@kcau.ac.ke', array_column($ix['chiefs'], 'email'), true), 'index lists chief supervisors only');
[$c, $m] = fails(fn() => $t->preview($DIR, ['period' => '', 'csv' => "$H\nx"])); ok($c === 422 && str_starts_with($m, 'Enter the exam period'), 'period is required');
[$c, $m] = fails(fn() => $t->preview($DIR, $P + ['csv' => "  \n "])); ok($c === 422 && $m === 'Paste or choose a timetable file first.', 'empty file rejected');
[$c] = fails(fn() => $t->preview($DIR, $P + ['csv' => str_repeat('x', 400001)])); ok($c === 413, 'oversized file rejected');
[$c] = fails(fn() => $t->show($DIR, 999)); ok($c === 404, 'unknown upload is 404');

// ------------------------------------------------------------------------------------------------
echo "Messy CSV normalisation\n";
[$t] = fresh();
$csv = "\xEF\xBB\xBFUnit Code;Exam Date;Start Time;End Time;Room;Lecturer;Candidates\r\n"
     . "bsd4101;03/11/2026;9am;12.00;hall  c;\"Dr G Wanjiru\";120\r\n"
     . "\r\n"
     . "\"BSD 4102\";3 Nov 2026;09:00;12:00;HALL C ;;\"1,000\"\r\n"
     . "bit-3202;2026-11-04;2:00pm;5pm;lab 2a;Dr Nobody;40\r\n";
$imp = pv($t, $DIR, $csv);
$tidy = $imp['plan']['tidy']; $S = sittings($imp);
ok($imp['plan']['errors'] === [], 'messy but valid file has no errors', json_encode($imp['plan']['errors']));
ok(in_array('Line 2: unit "bsd4101" written as BSD 4101', $tidy, true), 'unit code normalised and reported');
ok(in_array('Line 2: date "03/11/2026" read as 2026-11-03', $tidy, true) && in_array('Line 3: date "3 Nov 2026" read as 2026-11-03', $tidy, true), 'day-first and "3 Nov 2026" dates read');
ok(in_array('Line 2: venue "hall c" written as Hall C', $tidy, true), 'venue title-cased and spaces collapsed (blank line skipped: line numbers count non-empty rows)', json_encode($tidy));
ok(in_array('Line 4: unit "bit-3202" written as BIT 3202', $tidy, true) && in_array('Line 4: venue "lab 2a" written as Lab 2A', $tidy, true), 'bit-3202 and "lab 2a" tidied');
ok(in_array('Line 2: lecturer "Dr G Wanjiru" matched to Dr. Grace Wanjiru', $tidy, true), 'lecturer matched by initial and surname');
ok(in_array('Line 4: lecturer "Dr Nobody" is not on the portal staff list, so the own-paper rule cannot be applied for them.', $imp['plan']['warns'], true), 'unknown lecturer warns');
ok(count($S) === 2 && $S[0]['units'] === ['BSD 4101', 'BSD 4102'] && $S[0]['venue'] === 'Hall C' && $S[0]['key'] === '2026-11-03|09:00|12:00|HALLC', 'rows grouped into one sitting by date|start|end|venue');
ok($S[0]['candidates'] === 1120 && $S[0]['need'] === 28, 'candidates summed ("1,000" -> 1000), need = ceil(1120/40)', "{$S[0]['candidates']} {$S[0]['need']}");
ok($S[0]['opens_at'] === '2026-11-03T06:00:00Z' && $S[0]['closes_at'] === '2026-11-03T09:00:00Z', 'times stored as UTC (Nairobi is +03:00)');
ok($S[1]['venue'] === 'Lab 2A' && $S[1]['opens_at'] === '2026-11-04T11:00:00Z' && $S[1]['closes_at'] === '2026-11-04T14:00:00Z', '2:00pm-5pm read correctly');
ok($S[0]['school'] === 'School of Technology', 'sitting school from unit list');
ok(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $S[0]['opens_at']) === 1, 'UTC format matches Util::toUtc');
$none = pv($t, $DIR, "Foo,Bar\n1,2");
ok(errTexts($none) === ['Missing column(s): unit, date, start, end, venue. Needed: Unit, Date, Start, End, Venue (Lecturer and Candidates are optional).'] && $none['plan']['errors'][0]['line'] === 1, 'missing columns reported');
$only = pv($t, $DIR, $H);
ok(errTexts($only) === ['The file has no timetable rows. The first line must be the column headings.'] && $only['plan']['errors'][0]['line'] === 0, 'header-only file reported');
$dup = pv($t, $DIR, "$H\nBSD 4101,3/11/2026,9:00,12:00,Hall A,,10\nbsd4101,3/11/2026,9:00,12:00,hall a,,10");
ok($dup['plan']['warns'] === ['Line 3: BSD 4101 appears twice for the same sitting; counted once.'] && sittings($dup)[0]['candidates'] === 10, 'duplicate unit in a sitting counted once with a warning');
$noCand = pv($t, $DIR, "$H\nBSD 4103,3/11/2026,9:00,12:00,Hall A,,");
ok(sittings($noCand)[0]['candidates'] === 4, 'blank Candidates falls back to portal registrations');
$utf = pv($t, $DIR, "$H\nBSD 4101,3/11/2026,9:00,12:00,Caf\xE9 Hall,,10");
ok(sittings($utf)[0]['venue'] === 'Café Hall' || $utf['plan']['errors'] === [], 'Windows-1252 bytes are converted to UTF-8 instead of breaking JSON', sittings($utf)[0]['venue'] ?? '');
json_encode($utf, JSON_THROW_ON_ERROR);

// ------------------------------------------------------------------------------------------------
echo "Dates and times in mixed formats\n";
[$t] = fresh();
$yr = (int)(new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('Y');
$mixed = implode("\n", [$H,
    'BSD 4101,03/11/2026,9am,12pm,Hall 1,,10',
    'BSD 4102,2026-11-04,09.00,12.00,Hall 1,,10',
    'BSD 4103,Thu 5th Nov 2026,2:00pm,5:00pm,Hall 1,,10',
    'BIT 3201,6-11-26,14h00,17h00,Hall 1,,10',
    'BIT 3202,7 November,12am,1am,Hall 1,,10',
    'BBM 3101,"8 Nov. 2026",9:30 AM,11:45 a.m.,Hall 1,,10',
    'BBM 3102,31/02/2026,9am,12pm,Hall 1,,10',
    'BED 2101,10/11/2026,13pm,12pm,Hall 1,,10',
    'BED 2102,10/11/2026,10am,9am,Hall 1,,10',
    'BSD 4101,10/11/2026,,12pm,,,10',
    'BSD 4102,2nd Foo 2026,9am,12pm,Hall 1,,10']);
$imp = pv($t, $DIR, $mixed);
$by = []; foreach (sittings($imp) as $s) $by[$s['date'] . ' ' . $s['start']] = $s;
ok(isset($by['2026-11-03 09:00']) && $by['2026-11-03 09:00']['end'] === '12:00', '9am / 12pm');
ok(isset($by['2026-11-04 09:00']) && $by['2026-11-04 09:00']['end'] === '12:00', '09.00 / 12.00');
ok(isset($by['2026-11-05 14:00']) && $by['2026-11-05 14:00']['end'] === '17:00', 'Thu 5th Nov 2026, 2:00pm-5:00pm');
ok(isset($by['2026-11-06 14:00']) && $by['2026-11-06 14:00']['end'] === '17:00', '6-11-26 (two-digit year) and 14h00');
ok(isset($by["$yr-11-07 00:00"]) && $by["$yr-11-07 00:00"]['end'] === '01:00', "'7 November' takes the current year; 12am is midnight");
ok(isset($by['2026-11-08 09:30']) && $by['2026-11-08 09:30']['end'] === '11:45', '"8 Nov. 2026", 9:30 AM, 11:45 a.m.');
$errs = errTexts($imp);
ok(in_array('Date "31/02/2026" is not understood. Use for example 03/11/2026 or 3 Nov 2026.', $errs, true), '31/02 rejected');
ok(in_array('Start time "13pm" is not understood. Use for example 09:00 or 2pm.', $errs, true), '13pm rejected');
ok(in_array('End time must be after the start time.', $errs, true), 'end before start rejected');
ok(in_array('Venue is empty.', $errs, true) && in_array('Start time "" is not understood. Use for example 09:00 or 2pm.', $errs, true), 'empty venue / start rejected');
ok(in_array('Date "2nd Foo 2026" is not understood. Use for example 03/11/2026 or 3 Nov 2026.', $errs, true), 'unknown month name rejected');
$e0 = $imp['plan']['errors'][0];
ok(array_keys($e0) === ['line', 'field', 'found', 'msg', 'suggest'] && $e0['field'] === 'date' && $e0['found'] === '31/02/2026' && $e0['suggest'] === null, 'error object shape');

// ------------------------------------------------------------------------------------------------
echo "Unit typo suggestions\n";
[$t] = fresh();
$imp = pv($t, $DIR, implode("\n", [$H,
    'BIT 32O1,3/11/2026,9:00,12:00,Hall 1,,10',   // letter O for zero: distance 1, unique
    'BSD 4104,3/11/2026,9:00,12:00,Hall 2,,10',   // equally close to 4101, 4102, 4103: no guess
    'XYZ 9999,3/11/2026,9:00,12:00,Hall 3,,10',   // nothing close
    ',3/11/2026,9:00,12:00,Hall 4,,10']));
$E = $imp['plan']['errors'];
ok($E[0]['msg'] === '"BIT 32O1" is not a unit on the portal. Did you mean BIT 3201?' && $E[0]['suggest'] === 'BIT 3201', 'typo gets a unique suggestion');
ok($E[1]['msg'] === '"BSD 4104" is not a unit on the portal.' && $E[1]['suggest'] === null, 'tied best match gives no suggestion');
ok($E[2]['msg'] === '"XYZ 9999" is not a unit on the portal.' && $E[2]['suggest'] === null, 'distance > 2 gives no suggestion');
ok($E[3]['msg'] === 'Unit is empty.', 'empty unit');
ok(sittings($imp) === [], 'rows with errors do not become sittings');

// ------------------------------------------------------------------------------------------------
echo "Hall double-booking\n";
[$t] = fresh();
$imp = pv($t, $DIR, implode("\n", [$H,
    'BSD 4101,3/11/2026,9:00,12:00,Hall C,,50',
    'BIT 3201,3 Nov 2026,11:00,14:00,hall c,,50',
    'BSD 4102,3/11/2026,14:00,16:00,Hall C,,50',      // starts exactly when the second ends: fine
    'BSD 4103,3/11/2026,9:00,12:00,Hall D,,50']));
ok(errTexts($imp) === ['Hall C is double-booked: BSD 4101 (09:00-12:00) overlaps BIT 3201 (11:00-14:00) on 2026-11-03.'], 'overlap in same hall is an error (only the true overlap)', json_encode(errTexts($imp)));
ok($imp['plan']['errors'][0]['line'] === 2, 'error points at the first line of the first sitting');
ok(count(sittings($imp)) === 4, 'all sittings still planned');
[$c, $m] = fails(fn() => $t->submit($DIR, $imp['id'])); ok($c === 409 && $m === 'Fix the highlighted errors before submitting.', 'cannot submit with errors');
[$c, $m] = fails(fn() => $t->approve($DIR, $imp['id'])); ok($c === 409 && $m === 'Fix the highlighted errors first.', 'cannot approve with errors');

// ------------------------------------------------------------------------------------------------
echo "Assignment rules\n";
[$t] = fresh();
// Own paper: Grace teaches BSD 4101 on the portal; the Lecturer column adds Brian for BSD 4102.
$imp = pv($t, $DIR, implode("\n", [$H,
    'BSD 4101,3/11/2026,9:00,12:00,Hall A,,60',
    'BSD 4102,3/11/2026,9:00,12:00,Hall A,Brian Kiprop,60']));
$s = sittings($imp)[0];
ok(!in_array('grace.wanjiru@kcau.ac.ke', people($s), true), 'portal "teaches" keeps the lecturer off their own paper');
ok(!in_array('brian.kiprop@kcau.ac.ke', people($s), true), 'Lecturer column keeps that person off the paper too');
ok($s['lect']['BSD 4101'] === ['grace.wanjiru@kcau.ac.ke'] && $s['lect']['BSD 4102'] === ['brian.kiprop@kcau.ac.ke'], 'lecturers recorded per unit');
ok($s['need'] === 3 && count($s['invs']) === 3 && $s['problems'] === [], 'need = ceil(120/40) = 3 invigilators, no problems', json_encode($s['problems']));
ok(str_starts_with($s['notes'][0], 'Chief: ') && str_ends_with($s['notes'][0], ' other sittings so far, same school.'), 'chief note text', $s['notes'][0] ?? '');
$manual = $t->assign($DIR, $imp['id'], ['key' => $s['key'], 'slot' => 'chief', 'email' => 'grace.wanjiru@kcau.ac.ke'])['import'];
ok(in_array('Dr. Grace Wanjiru teaches a paper in this session and cannot invigilate it.', sittings($manual)[0]['problems'], true), 'manual assignment of the lecturer is flagged');

// Buffer: second sitting starts 15 minutes after the first ends in another hall.
[$t] = fresh();
$imp = pv($t, $DIR, implode("\n", [$H,
    'BSD 4101,3/11/2026,9:00,12:00,Hall A,,40',
    'BSD 4102,3/11/2026,12:15,15:00,Hall B,,40',
    'BIT 3201,3/11/2026,12:30,15:00,Hall C,,40']));
[$a, $b, $c3] = sittings($imp);
ok(array_intersect(people($a), people($b)) === [], 'nobody works two sittings inside the 30-minute buffer');
ok(array_intersect(people($b), people($c3)) === [], 'overlapping sittings share nobody');
ok($imp['plan']['sessions'][0]['problems'] === [] && $b['problems'] === [] && $c3['problems'] === [], 'plan is clean');
$reuse = array_intersect(people($a), people($c3));
ok(count($reuse) > 0, 'exactly 30 minutes apart, people can be reused (12:30 start vs 12:00 end)', json_encode($reuse));

// Workload spread: four Business sittings on four days, need 1 invigilator each. Same-school preference beats an idle outsider (1 < 1.5).
[$t] = fresh();
$imp = pv($t, $DIR, implode("\n", [$H,
    'BED 2102,2/11/2026,9:00,12:00,Hall A,,30', 'BED 2102,3/11/2026,9:00,12:00,Hall A,,30', 'BED 2102,4/11/2026,9:00,12:00,Hall A,,30', 'BED 2102,5/11/2026,9:00,12:00,Hall A,,30']));
$S = sittings($imp);
$load = array_count_values(array_merge(...array_map('people', $S)));
ok(max($load) <= 2 && count($S) === 4, 'no one is overloaded', json_encode($load));
$edu = ['joyce.wambui@kcau.ac.ke', 'kevin.omondi@kcau.ac.ke', 'mercy.chebet@kcau.ac.ke'];
$invLoad = array_count_values(array_merge(...array_map(fn($s) => $s['invs'], $S)));
ok(count($S[0]['invs']) === 1 && $S[0]['invs'][0] === 'mercy.chebet@kcau.ac.ke', 'first sitting: idle same-school invigilator (Kevin teaches the paper, so Mercy)', json_encode($S[0]['invs']));
ok(max($invLoad) - min($invLoad) <= 1 || count($invLoad) >= 2, 'invigilator work is spread over several people', json_encode($invLoad));
$wl = $imp['plan']['workload'];
$sum = array_sum(array_column($wl, 'sittings'));
ok($sum === 8 && $wl[0]['sittings'] >= end($wl)['sittings'], 'workload summary sorted, totals chief + invigilators', json_encode($wl));
// supervisors are only used as invigilators when needed: the .25 penalty
[$t] = fresh();
$imp = pv($t, $DIR, "$H\nBSD 4102,3/11/2026,9:00,12:00,Hall A,,200");
$s = sittings($imp)[0]; // needs 5 invigilators; Technology has 5 invigilator accounts, none teaching 4102
$roles = []; foreach ($s['invs'] as $e) $roles[] = in_array($e, ['supervisor@kcau.ac.ke', 'grace.wanjiru@kcau.ac.ke', 'peter.otieno@kcau.ac.ke'], true) ? 'sup' : 'inv';
ok($s['need'] === 5 && !in_array('sup', $roles, true), 'chief-eligible staff are not used while plain invigilators are free', json_encode($s['invs']));
$imp = pv($t, $DIR, "$H\nBSD 4102,3/11/2026,9:00,12:00,Hall A,,520"); // 13 needed
$supers = ['supervisor@kcau.ac.ke', 'grace.wanjiru@kcau.ac.ke', 'peter.otieno@kcau.ac.ke', 'anne.njeri@kcau.ac.ke', 'david.kamau@kcau.ac.ke', 'joyce.wambui@kcau.ac.ke'];
$roles = array_map(fn($e) => in_array($e, $supers, true) ? 'sup' : 'inv', sittings($imp)[0]['invs']);
// score = load + 1.5 if another school (+ .25 for chief-eligible): 5 own-school invigilators (0), 2 own-school supervisors (.25), 5 other-school invigilators (1.5), then a supervisor (1.75)
ok(count($roles) === 13 && implode(',', $roles) === 'inv,inv,inv,inv,inv,sup,sup,inv,inv,inv,inv,inv,sup', 'chief-eligible staff carry a .25 penalty, other-school staff 1.5', implode(',', $roles));
// shortage
$imp = pv($t, $DIR, "$H\nBSD 4102,3/11/2026,9:00,12:00,Hall A,,4000");
$s = sittings($imp)[0];
ok($s['need'] === 100 && in_array('Needs 100 invigilator(s) for 4000 candidates; ' . count($s['invs']) . ' assigned.', $s['problems'], true), 'shortage reported as a problem');
ok(count($s['invs']) === count(array_unique($s['invs'])), 'no one is listed twice');

// ------------------------------------------------------------------------------------------------
echo "Manual assignment and re-validation\n";
[$t] = fresh();
$imp = pv($t, $DIR, implode("\n", [$H,
    'BSD 4102,3/11/2026,9:00,12:00,Hall A,,80',
    'BIT 3202,3/11/2026,10:00,13:00,Hall B,,40']));
[$a, $b] = sittings($imp);
$id = $imp['id'];
$busyInB = $b['invs'][0];
$r = $t->assign($DIR, $id, ['key' => $a['key'], 'slot' => 'inv', 'index' => 0, 'email' => $busyInB])['import'];
ok(in_array(staffName($busyInB) . ' is already assigned to another sitting at this time.', sittings($r)[0]['problems'], true) && in_array(staffName($busyInB) . ' is already assigned to another sitting at this time.', sittings($r)[1]['problems'], true), 'double-booking a person flags both sittings');
function staffName(string $email): string { foreach ((new MockPortal())->listStaff() as $u) if ($u['email'] === $email) return $u['name']; return $email; }
ok(in_array('Changed by Exam Director.', sittings($r)[0]['notes'], true) && !array_filter(sittings($r)[0]['notes'], fn($n) => str_starts_with($n, 'Chief:')), 'assignment note replaces the chief note');
$r = $t->assign($DIR, $id, ['key' => $a['key'], 'slot' => 'inv', 'index' => 1, 'email' => sittings($r)[0]['invs'][0]])['import'];
ok(in_array(staffName(sittings($r)[0]['invs'][0]) . ' is listed twice.', sittings($r)[0]['problems'], true), 'same person twice flagged');
$r = $t->assign($DIR, $id, ['key' => $a['key'], 'slot' => 'inv', 'index' => 1, 'email' => ''])['import'];
ok(count(sittings($r)[0]['invs']) === 1 && in_array('Needs 2 invigilator(s) for 80 candidates; 1 assigned.', sittings($r)[0]['problems'], true), 'removing an invigilator re-validates the headcount');
$r = $t->assign($DIR, $id, ['key' => $a['key'], 'slot' => 'chief', 'email' => 'invigilator1@kcau.ac.ke'])['import'];
ok(in_array('The chief must be a chief-supervisor account.', sittings($r)[0]['problems'], true), 'chief must be a supervisor account');
$r = $t->assign($DIR, $id, ['key' => $a['key'], 'slot' => 'chief', 'email' => null])['import'];
ok(in_array('No chief supervisor is free for this sitting. Choose one manually.', sittings($r)[0]['problems'], true), 'clearing the chief flagged');
[$c, $m] = fails(fn() => $t->assign($DIR, $id, ['key' => $a['key'], 'slot' => 'chief', 'email' => 'nobody@kcau.ac.ke'])); ok($c === 422 && $m === 'Choose a staff account from the list.', 'unknown account refused');
[$c, $m] = fails(fn() => $t->assign($DIR, $id, ['key' => $a['key'], 'slot' => 'chief', 'email' => 'director@kcau.ac.ke'])); ok($c === 422, 'non-pool account refused');
[$c, $m] = fails(fn() => $t->assign($DIR, $id, ['key' => $a['key'], 'slot' => 'nope'])); ok($c === 422 && $m === 'Unknown slot.', 'unknown slot refused');
[$c, $m] = fails(fn() => $t->assign($DIR, $id, ['key' => 'zzz', 'slot' => 'chief'])); ok($c === 404 && $m === 'Sitting not found.', 'unknown sitting 404');
$reload = $t->show($DIR, $id)['import'];
ok(sittings($reload)[0]['chief'] === null && count(sittings($reload)[0]['invs']) === 1, 'assignment persisted');
[$c, $m] = fails(fn() => $t->submit($DIR, $id)); ok($c === 409 && $m === 'Resolve the assignment problems before submitting.', 'cannot submit with assignment problems');

// ------------------------------------------------------------------------------------------------
echo "Coordinator restrictions\n";
[$t] = fresh();
$csv = implode("\n", [$H, 'BSD 4101,3/11/2026,9:00,12:00,Hall A,,40', 'BBM 3101,3/11/2026,9:00,12:00,Hall B,,40']);
$imp = pv($t, $TECH, $csv, ['school' => 'School of Business']);
ok($imp['school'] === 'School of Technology', "coordinator's school is forced, whatever the form says");
ok(errTexts($imp) === ['BBM 3101 belongs to School of Business, not School of Technology. Only that school\'s coordinator can upload it.'], 'other school\'s unit refused for a coordinator', json_encode(errTexts($imp)));
ok(count(sittings($imp)) === 1, 'only the own-school row is planned');
[$c, $m] = fails(fn() => $t->submit($TECH, $imp['id'])); ok($c === 409, 'coordinator cannot submit a file with errors');
$dirImp = pv($t, $DIR, $csv, ['school' => 'School of Technology']);
ok($dirImp['school'] === 'School of Technology' && count(errTexts($dirImp)) === 1, 'director can pick a school too');
$all = pv($t, $DIR, $csv);
ok($all['school'] === 'All schools' && $all['plan']['errors'] === [], 'director default is All schools, no restriction');
$ix = $t->index($TECH);
ok(count($ix['imports']) === 1 && $ix['imports'][0]['id'] === $imp['id'] && $ix['school'] === 'School of Technology', 'coordinator sees own uploads only');
$ix = $t->index($DIR);
ok(count($ix['imports']) === 2 && $ix['imports'][0]['id'] > $ix['imports'][1]['id'], 'director sees all, newest first');
[$c, $m] = fails(fn() => $t->show($TECH2, $imp['id'])); ok($c === 403 && $m === 'That upload belongs to another coordinator.', 'other coordinator cannot open it');
[$c] = fails(fn() => $t->assign($TECH2, $imp['id'], ['key' => 'x'])); ok($c === 403, 'other coordinator cannot assign');
[$c] = fails(fn() => $t->submit($TECH2, $imp['id'])); ok($c === 403, 'other coordinator cannot submit');
[$c] = fails(fn() => $t->show($TECH, $dirImp['id'])); ok($c === 403, 'coordinator cannot open the director\'s upload');
ok($t->show($DIR, $imp['id'])['import']['id'] === $imp['id'], 'director can open any upload');
$again = pv($t, $TECH, $csv);
$rows = fn() => count($t->index($TECH)['imports']);
ok($rows() === 1 && $again['id'] !== $imp['id'], 'a new preview replaces the coordinator\'s previous draft');
$imp = $again;

// ------------------------------------------------------------------------------------------------
echo "Workflow and permissions\n";
$clean = implode("\n", [$H, 'BSD 4101,3/11/2026,9:00,12:00,Hall A,,40', 'BSD 4102,3/11/2026,13:00,16:00,Hall B,,40']);
$imp = pv($t, $TECH, $clean);
$id = $imp['id'];
ok($imp['status'] === 'draft' && $imp['plan']['errors'] === [] && array_filter(sittings($imp), fn($s) => $s['problems']) === [], 'clean upload is a draft with no problems');
[$c, $m] = fails(fn() => $t->approve($TECH, $id)); ok($c === 403 && $m === 'Only the exam director can approve a timetable.', 'coordinator cannot approve');
[$c, $m] = fails(fn() => $t->reject($TECH, $id, ['note' => 'x'])); ok($c === 403 && $m === 'Only the exam director can reject a timetable.', 'coordinator cannot reject');
$sub = $t->submit($TECH, $id)['import'];
ok($sub['status'] === 'pending' && $sub['sessions'] === 2 && !isset($sub['plan']), 'coordinator submits; submit returns the summary shape');
[$c, $m] = fails(fn() => $t->submit($TECH, $id)); ok($c === 409 && $m === 'Already submitted.', 'cannot submit twice');
[$c, $m] = fails(fn() => $t->assign($TECH, $id, ['key' => sittings($imp)[0]['key'], 'slot' => 'chief', 'email' => 'supervisor@kcau.ac.ke'])); ok($c === 403 && $m === 'Only the exam director can change a submitted timetable.', 'coordinator cannot edit after submitting');
$r = $t->assign($DIR, $id, ['key' => sittings($imp)[0]['key'], 'slot' => 'chief', 'email' => 'supervisor@kcau.ac.ke'])['import'];
ok(sittings($r)[0]['chief'] === 'supervisor@kcau.ac.ke', 'director can edit a submitted timetable');
$ix = $t->index($DIR); ok($ix['imports'][0]['status'] === 'pending', 'index shows pending');
$rej = $t->reject($DIR, $id, ['note' => '  Hall A   is   needed for a graduation rehearsal  '])['import'];
ok($rej['status'] === 'rejected' && $rej['note'] === 'Hall A is needed for a graduation rehearsal' && $rej['decided_by'] === 'Exam Director', 'director rejects with a tidied note');
[$c, $m] = fails(fn() => $t->approve($DIR, $id)); ok($c === 409 && $m === 'Already decided.', 'cannot approve after rejecting');
[$c] = fails(fn() => $t->reject($DIR, $id, [])); ok($c === 409, 'cannot reject twice');
[$c] = fails(fn() => $t->assign($DIR, $id, ['key' => 'x'])); ok($c === 409, 'cannot assign after a decision');
[$c] = fails(fn() => $t->reject($DIR, $prevId = $t->preview($DIR, $P + ['csv' => $clean])['import']['id'], ['note' => str_repeat('n', 201)])); ok($c === 422, 'reject note length capped');
$nrows = (int)$db->query('SELECT COUNT(*) FROM exam_sessions')->fetchColumn();
ok($nrows === 0, 'nothing was created by a rejection');
unset($db);

// ------------------------------------------------------------------------------------------------
echo "Approval creates sessions, access and audit entries\n";
[$t, $db, $audit] = fresh();
$csv = implode("\n", [$H,
    'BSD 4101,3/11/2026,9:00,12:00,hall a,Grace Wanjiru,80',
    'BSD 4102,3/11/2026,9:00,12:00,Hall A,,40',
    'BIT 3202,4/11/2026,2pm,5pm,Lab 1,,45']);
$imp = pv($t, $TECH, $csv, ['late_after' => 20]);
$id = $imp['id'];
$planned = sittings($imp);
$t->submit($TECH, $id);
$ap = $t->approve($DIR, $id);
ok($ap['created'] === 2 && $ap['import']['status'] === 'approved' && $ap['import']['decided_by'] === 'Exam Director' && count($ap['import']['session_ids']) === 2, 'approve returns {import, created}', json_encode($ap));
$rows = $db->query('SELECT * FROM exam_sessions ORDER BY id')->fetchAll();
$r0 = $rows[0];
ok(count($rows) === 2 && $r0['period'] === 'Sem 2 2026, Main exams' && $r0['venue'] === 'Hall A' && $r0['status'] === 'active', 'exam_sessions rows created');
ok($r0['opens_at'] === '2026-11-03T06:00:00Z' && $r0['closes_at'] === '2026-11-03T09:00:00Z', 'UTC times stored');
ok(json_decode($r0['units']) === ['BSD 4101', 'BSD 4102'] && (int)$r0['late_after'] === 20 && (int)$r0['offline'] === 0 && $r0['source'] === 'timetable' && (int)$r0['import_id'] === $id, 'units JSON, late_after, offline, source, import_id');
ok(preg_match('/^S261103-[0-9A-F]{4}$/', $r0['code']) === 1 && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $r0['created_at']) === 1, 'session code and created_at format', $r0['code']);
ok($r0['supervisor_email'] === $planned[0]['chief'] && $r0['supervisor_name'] === staffName($planned[0]['chief']), 'chief becomes the session supervisor');
$acc = $db->query("SELECT * FROM session_access WHERE session_id={$r0['id']} ORDER BY id")->fetchAll();
ok(array_column($acc, 'staff_email') === $planned[0]['invs'] && count($acc) === $planned[0]['need'] && array_unique(array_column($acc, 'granted_by')) === ['timetable'] && array_filter($acc, fn($a) => $a['revoked_at'] !== null) === [], 'invigilators get session_access rows granted by timetable');
ok(!in_array($r0['supervisor_email'], array_column($acc, 'staff_email'), true), 'the chief is not also an invigilator');
$acts = $db->query('SELECT action, actor, session_id, payload FROM audit_log ORDER BY id')->fetchAll();
$names = array_column($acts, 'action');
ok($names === ['timetable_submitted', 'session_created', 'session_created', 'timetable_approved'], 'audit trail', json_encode($names));
$sc = $acts[1];
ok($sc['actor'] === 'director@kcau.ac.ke' && (int)$sc['session_id'] === (int)$r0['id'] && json_decode($sc['payload'], true) === ['from' => 'timetable', 'import' => $id, 'period' => 'Sem 2 2026, Main exams', 'venue' => 'Hall A'], 'session_created entry');
ok(json_decode($acts[3]['payload'], true) === ['import' => $id, 'sessions' => 2] && $acts[3]['session_id'] === null, 'timetable_approved entry');
ok($audit->verify()['ok'] === true, 'audit chain verifies');
[$c, $m] = fails(fn() => $t->approve($DIR, $id)); ok($c === 409 && $m === 'Already decided.', 'approving twice is refused (no duplicate sessions)');
ok((int)$db->query('SELECT COUNT(*) FROM exam_sessions')->fetchColumn() === 2, 'still two sessions');
$shown = $t->show($TECH, $id)['import'];
ok($shown['status'] === 'approved' && $shown['session_ids'] === array_map('intval', array_column($rows, 'id')), 'show after approval lists session ids');

echo "Clashes with sessions already loaded\n";
$again = pv($t, $DIR, $csv);
ok(in_array('Hall A on 2026-11-03 09:00-12:00 clashes with an existing session already loaded (BSD 4101, BSD 4102).', errTexts($again), true), 'same hall and time as an approved session is an error', json_encode(errTexts($again)));
ok(count(errTexts($again)) === 2, 'one clash per affected sitting', json_encode(errTexts($again)));
// People already on approved sessions are not reused at overlapping times, even in another hall (and inside the buffer).
$busy = array_merge([$r0['supervisor_email']], $planned[0]['invs']);
$other = pv($t, $DIR, "$H\nBIT 3201,3/11/2026,12:20,14:00,Hall Z,,160");
$ps = people(sittings($other)[0]);
ok(array_intersect($busy, $ps) === [], 'staff on an existing session are not re-assigned within the buffer', json_encode(array_intersect($busy, $ps)));
$late = pv($t, $DIR, "$H\nBIT 3201,3/11/2026,12:30,14:00,Hall Z,,40");
ok($late['plan']['errors'] === [], 'no clash for another hall');
// A closed session no longer blocks.
$db->exec("UPDATE exam_sessions SET status='closed'");
$free = pv($t, $DIR, $csv);
ok($free['plan']['errors'] === [], 'closed sessions do not clash');
$db->exec("UPDATE exam_sessions SET status='active'");

// Two drafts for the same hall: whichever is approved first wins, the other is refused at approval and shows the new error.
[$t, $db] = fresh();
$one = pv($t, $TECH, "$H\nBSD 4101,3/11/2026,9:00,12:00,Hall A,,40");
$two = pv($t, $TECH2, "$H\nBSD 4102,3/11/2026,10:00,12:00,hall a,,40");
ok($one['plan']['errors'] === [] && $two['plan']['errors'] === [], 'both drafts look fine on their own');
$t->approve($DIR, $one['id']);
[$c, $m] = fails(fn() => $t->approve($DIR, $two['id']));
ok($c === 409 && $m === 'Fix the highlighted errors first.', 'second approval is refused after the first took the hall', "$c $m");
$re = $t->show($DIR, $two['id'])['import'];
ok($re['status'] === 'draft' && $re['plan']['errors'] !== [] && str_contains($re['plan']['errors'][0]['msg'], 'clashes with an existing session already loaded (BSD 4101)'), 'refreshed clash error is stored on the upload');
ok((int)$db->query('SELECT COUNT(*) FROM exam_sessions')->fetchColumn() === 1, 'nothing extra created');

// Staff clash after approval: a person given to an approved session is flagged if added by hand elsewhere.
$three = pv($t, $DIR, "$H\nBIT 3201,3/11/2026,10:00,12:00,Hall Q,,40");
$sup = $db->query('SELECT supervisor_email FROM exam_sessions')->fetchColumn();
$r = $t->assign($DIR, $three['id'], ['key' => sittings($three)[0]['key'], 'slot' => 'chief', 'email' => $sup])['import'];
ok(in_array(staffName($sup) . ' is already assigned to another sitting at this time.', sittings($r)[0]['problems'], true), 'person on an approved session flagged when added by hand');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
