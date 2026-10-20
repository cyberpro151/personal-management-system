<?php

namespace App\Enum;

/**
 * Names of the Pendo Track Events sent via {@see \App\Services\External\PendoTrackService}.
 *
 * Values must be kept exactly as they are - Pendo matches track types by the (case-sensitive) name,
 * so changing any of these would create a new track type and split the already collected metrics.
 */
enum PendoTrackEventEnum: string
{
    case MONTHLY_PAYMENTS_IMPORT_COMPLETED = "monthly_payments_import_completed";
    case FILE_UPLOAD_COMPLETED             = "file_upload_completed";
    case GLOBAL_SEARCH_COMPLETED           = "global_search_completed";
    case TODO_LIST_COMPLETED               = "todo_list_completed";
    case SAVINGS_GOAL_REACHED              = "savings_goal_reached";
    case RECURRING_PAYMENTS_APPLIED        = "recurring_payments_applied";
    case SCHEDULE_REMINDER_SENT            = "schedule_reminder_sent";
    case SCHEDULE_SAVED                    = "schedule_saved";
    case USER_REGISTERED                   = "user_registered";
    case SYSTEM_LOCK_TOGGLED               = "system_lock_toggled";
    case DASHBOARD_WIDGETS_CONFIGURED      = "dashboard_widgets_configured";
    case MODULE_LOCKS_CONFIGURED           = "module_locks_configured";
    case REMINDER_NOTIFICATIONS_CONFIGURED = "reminder_notifications_configured";
    case STORAGE_FILES_MOVED               = "storage_files_moved";
    case STORAGE_FILES_REMOVED             = "storage_files_removed";
    case STORAGE_FOLDER_MOVED_OR_COPIED    = "storage_folder_moved_or_copied";
}
