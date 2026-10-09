<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Leave Requests table
        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                if (!Schema::hasColumn('leave_requests', 'cancellation_reason')) {
                    $table->text('cancellation_reason')->nullable()->after('rejection_reason');
                }
                if (!Schema::hasColumn('leave_requests', 'cancelled_by')) {
                    $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('leave_requests', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
                }
            });
        }

        // 2. Regularization Requests table
        if (Schema::hasTable('regularization_requests')) {
            Schema::table('regularization_requests', function (Blueprint $table) {
                if (!Schema::hasColumn('regularization_requests', 'cancellation_reason')) {
                    $table->text('cancellation_reason')->nullable()->after('rejection_reason');
                }
                if (!Schema::hasColumn('regularization_requests', 'cancelled_by')) {
                    $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('regularization_requests', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
                }
            });
        }

        // 3. Visits table
        if (Schema::hasTable('visits')) {
            Schema::table('visits', function (Blueprint $table) {
                if (!Schema::hasColumn('visits', 'device_id')) {
                    $table->string('device_id', 64)->nullable()->after('personnel_id');
                    $table->foreign('device_id')->references('device_id')->on('devices')->nullOnDelete();
                }
                if (!Schema::hasColumn('visits', 'expected_departure')) {
                    $table->timestamp('expected_departure')->nullable()->after('expected_arrival');
                }
                if (!Schema::hasColumn('visits', 'overstay_alerted_at')) {
                    $table->timestamp('overstay_alerted_at')->nullable()->after('check_out_time');
                }
                if (!Schema::hasColumn('visits', 'cancellation_reason')) {
                    $table->text('cancellation_reason')->nullable()->after('status');
                }
                if (!Schema::hasColumn('visits', 'cancelled_by')) {
                    $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('visits', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
                }

                $table->index(['status', 'expected_departure'], 'visits_status_expected_departure_idx');
                $table->index(['status', 'expected_arrival'], 'visits_status_expected_arrival_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('visits')) {
            Schema::table('visits', function (Blueprint $table) {
                $table->dropIndex('visits_status_expected_departure_idx');
                $table->dropIndex('visits_status_expected_arrival_idx');

                if (Schema::hasColumn('visits', 'cancelled_by')) {
                    $table->dropForeign(['cancelled_by']);
                    $table->dropColumn('cancelled_by');
                }
                if (Schema::hasColumn('visits', 'device_id')) {
                    $table->dropForeign(['device_id']);
                    $table->dropColumn('device_id');
                }
                if (Schema::hasColumn('visits', 'cancellation_reason')) {
                    $table->dropColumn('cancellation_reason');
                }
                if (Schema::hasColumn('visits', 'cancelled_at')) {
                    $table->dropColumn('cancelled_at');
                }
                if (Schema::hasColumn('visits', 'expected_departure')) {
                    $table->dropColumn('expected_departure');
                }
                if (Schema::hasColumn('visits', 'overstay_alerted_at')) {
                    $table->dropColumn('overstay_alerted_at');
                }
            });
        }

        if (Schema::hasTable('regularization_requests')) {
            Schema::table('regularization_requests', function (Blueprint $table) {
                if (Schema::hasColumn('regularization_requests', 'cancelled_by')) {
                    $table->dropForeign(['cancelled_by']);
                    $table->dropColumn('cancelled_by');
                }
                if (Schema::hasColumn('regularization_requests', 'cancellation_reason')) {
                    $table->dropColumn('cancellation_reason');
                }
                if (Schema::hasColumn('regularization_requests', 'cancelled_at')) {
                    $table->dropColumn('cancelled_at');
                }
            });
        }

        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                if (Schema::hasColumn('leave_requests', 'cancelled_by')) {
                    $table->dropForeign(['cancelled_by']);
                    $table->dropColumn('cancelled_by');
                }
                if (Schema::hasColumn('leave_requests', 'cancellation_reason')) {
                    $table->dropColumn('cancellation_reason');
                }
                if (Schema::hasColumn('leave_requests', 'cancelled_at')) {
                    $table->dropColumn('cancelled_at');
                }
            });
        }
    }
};
