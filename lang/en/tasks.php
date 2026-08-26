<?php

// Task Management (Aug 26, 2026)
return [
    'title' => 'Task Management',
    'one' => 'Task',
    'add' => 'Add Task',
    'submit_task' => 'Assign',
    'created' => 'Task sent — the employee got a notification',

    // Board
    'col_today' => "Today's tasks",
    'col_late' => 'Overdue',
    'col_done' => 'Done',
    'none' => 'Nothing here',
    'none_late' => 'Nothing overdue 👌',
    'i_assigned' => 'Tasks I assigned',
    'late' => 'Late',

    // Search & filters (Aug 26 — screen polish)
    'k_waiting' => 'Awaiting my approval',
    'search_ph' => 'Search by task title or employee name…',
    'all_priorities' => 'All priorities',
    'all_statuses' => 'All statuses',
    'showing' => 'Showing :n of :t',
    'pick_staff' => 'Pick employee',
    'title_ph' => 'e.g. Review August sales sheet',
    'desc_ph' => 'Describe what is needed…',
    'pick_files' => 'Choose files',

    // Dashboard widget (Aug 26)
    // Team board (Aug 26)
    'team_title' => 'Team board',
    'team_hint' => 'Click an employee to see only his tasks — click again to clear',
    'c_open' => 'working on',
    'c_late' => 'late',
    'c_wait' => 'awaiting approval',
    'c_done' => 'done',
    'clear_filters' => 'Clear filters',

    'my_widget' => 'My tasks',
    'go_all' => 'All tasks',
    'h_widget' => 'Your open tasks and the ones awaiting your approval — click any task to open it with its chat.',

    // Form
    'f_assignee' => 'Employee',
    'f_title' => 'Task title',
    'f_desc' => 'Description',
    'f_deadline' => 'Deadline',
    'f_priority' => 'Priority',
    'f_status' => 'Status',
    'f_files' => 'Attachments',
    'h_files' => 'Images, Excel sheets or PDFs — up to 8 files, 10 MB each.',

    // Priorities
    'pr_low' => 'Low',
    'pr_normal' => 'Normal',
    'pr_high' => 'High',
    'pr_urgent' => 'Urgent 🔥',

    // Statuses
    'st_open' => 'Open',
    'st_submitted' => 'Awaiting approval',
    'st_approved' => 'Approved',

    // Buttons & decisions
    'mark_done' => 'Mark delivered',
    'submit_confirm' => 'Sure the task is finished and ready for approval?',
    'approve' => 'Approve',
    'reject' => 'Reject',
    'reject_why' => 'Rejection reason? (will show in the task chat)',
    'submitted_ok' => 'Sent for approval — the assigner got a notification',
    'approved_ok' => 'Approved — task closed 🏁',
    'rejected_ok' => 'Rejected — back to open for the employee',
    'not_open' => 'Task is not open — cannot be delivered now.',
    'not_submitted' => 'Task is not awaiting approval.',
    'rejected_n' => 'Rejected :n times before',
    'by' => 'Assigned by',

    // Chat
    'chat' => 'Follow-up',
    'no_msgs' => 'No messages yet — start the follow-up here.',
    'msg_ph' => 'Write a message… (Where is the update? / Finished part one…)',
    'send' => 'Send',
    'empty_msg' => 'Write a message or attach a file.',
    'send_err' => 'Sending failed — try again.',
    'closed_hint' => 'Task approved and closed — chat is locked.',

    // System lines in chat
    'sys_submitted' => '✅ Employee delivered the task',
    'sys_approved' => '🏁 Assigner approved the task',
    'sys_rejected' => '↩️ Assigner rejected the delivery — task reopened',

    // Notifications
    'n_new_title' => 'New task for you',
    'n_new_body' => '":t" — from :by',
    'n_msg_title' => 'Message on a task',
    'n_msg_body' => '":t" — :by wrote a new message',
    'n_done_title' => 'Task delivered — awaiting your approval',
    'n_done_body' => '":t" — delivered by :by',
    'n_approved_title' => 'Your task was approved',
    'n_approved_body' => '":t" approved — thank you 🏁',
    'n_rejected_title' => 'Your task was rejected and reopened',
    'n_rejected_body' => '":t" — see the reason in the task chat',
];
