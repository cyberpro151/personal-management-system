<?php

namespace App\Enum;

/**
 * Names of the server side Pendo Track Events, sent via {@see \App\Services\External\PendoTrackService}.
 *
 * Pendo matches these names exactly (case-sensitive) with the already registered track types,
 * so the values must never be renamed / reformatted, else the events land in a different track type.
 */
enum PendoTrackEventEnum: string
{
    case FILE_UPLOAD_COMPLETED             = 'file_upload_completed';
    case MONTHLY_PAYMENTS_IMPORT_COMPLETED = 'monthly_payments_import_completed';
    case GLOBAL_SEARCH_COMPLETED           = 'global_search_completed';
    case TODO_LIST_COMPLETED               = 'todo_list_completed';
    case SAVINGS_GOAL_REACHED              = 'savings_goal_reached';
    case RECURRING_PAYMENTS_POSTED         = 'recurring_payments_posted';
    case SCHEDULE_REMINDERS_SENT           = 'schedule_reminders_sent';
    case SCHEDULE_REMINDERS_SET            = 'schedule_reminders_set';
    case NOTIFICATION_CHANNEL_CONFIGURED   = 'notification_channel_configured';
    case USER_REGISTRATION_COMPLETED       = 'user_registration_completed';
    case SYSTEM_LOCK_STATE_CHANGED         = 'system_lock_state_changed';
    case MODULE_LOCK_SETTINGS_UPDATED      = 'module_lock_settings_updated';
    case STORAGE_FILES_REMOVED             = 'storage_files_removed';
    case STORAGE_FILES_MOVED               = 'storage_files_moved';
    case STORAGE_FOLDER_TRANSFER_COMPLETED = 'storage_folder_transfer_completed';
    case HOLIDAY_DAYS_RECORDED             = 'holiday_days_recorded';
}
