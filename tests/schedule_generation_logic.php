<?php
declare(strict_types=1);

define('EASYSCHED_LIBRARY_MODE', true);
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api.php';

$filterIds = generation_filter_ids(['program_ids' => ['3', '4', '3']], 'program_ids');
if ($filterIds !== [3, 4]) {
    throw new RuntimeException('Manual generation filter IDs were not normalized.');
}

$invalidFilterRejected = false;
try {
    generation_filter_ids(['subject_ids' => ['0']], 'subject_ids');
} catch (ApiError $error) {
    $invalidFilterRejected = $error->status === 422;
}
if (!$invalidFilterRejected) {
    throw new RuntimeException('Manual generation accepted an invalid filter ID.');
}

$task = [
    'task_id' => '11:1',
    'offering_id' => 11,
    'meeting_no' => 1,
    'subject_name' => 'Test subject',
    'section_id' => 9,
    'section_code' => 'S1',
    'instructor_id' => 7,
    'instructor_name' => 'Test instructor',
    'max_hours_day' => 6,
    'enrollment' => 20,
    'duration_slots' => 1,
    'room_type' => 'LECTURE',
    'required_features' => [],
];
$context = [
    'slots' => [['id' => 1, 'slot_order' => 1, 'start_time' => '08:00:00', 'end_time' => '09:00:00']],
    'rooms' => [['id' => 2, 'capacity' => 30, 'room_type' => 'LECTURE', 'features' => []]],
    'blocked_room' => [],
    'blocked_instructor' => [],
    'blocked_section' => [],
    'preferences' => [],
];
$fixedTask = $task;
$fixedTask['task_id'] = '10:1';
$fixedTask['offering_id'] = 10;
$fixedAssignments = [[
    'task' => $fixedTask,
    'candidate' => ['room_id' => 2, 'day' => 1, 'slot_id' => 1, 'slot_ids' => [1], 'hours' => 1.0, 'cost' => 0.0],
]];
$solution = solve_schedule([$task], $context, 1000, $fixedAssignments);
if (!$solution['success'] || (int) $solution['assignments'][0]['candidate']['day'] === 1) {
    throw new RuntimeException('The solver did not avoid preserved schedule occupancy.');
}

echo "Manual filter validation and fixed-occupancy solver checks: OK\n";
