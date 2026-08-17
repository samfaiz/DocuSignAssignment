<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An optional photograph of the signer, taken at signing.
     *
     * Enabled per envelope by the sender rather than globally. A photograph of
     * a person's face is biometric data — special-category under GDPR Article 9
     * once it is used to identify someone, personal data with heightened duties
     * under India's DPDP Act, and actionable per-violation under Illinois BIPA.
     * Collecting it from every signer regardless of what they are signing would
     * be indefensible; collecting it when the sender decides a particular
     * document warrants it is a decision someone has actually made.
     *
     * What this is NOT: identity verification. That requires a government ID,
     * a face match against it, and liveness detection. Without those, a photo
     * establishes that someone was present and willing to be photographed —
     * genuinely useful in a dispute, but not proof of who they are, and the
     * certificate of completion says so in those words.
     */
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->boolean('require_photo')->default(false);
        });

        Schema::table('recipients', function (Blueprint $table) {
            $table->string('photo_consent', 16)->default('not_asked');
            $table->string('photo_storage_key')->nullable();
            $table->char('photo_sha256', 64)->nullable();
            $table->timestamp('photo_captured_at')->nullable();
        });

        DB::statement("
            ALTER TABLE recipients ADD CONSTRAINT recipients_photo_consent_check
            CHECK (photo_consent IN ('not_asked','granted','denied','unsupported','failed'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recipients DROP CONSTRAINT IF EXISTS recipients_photo_consent_check');

        Schema::table('recipients', function (Blueprint $table) {
            $table->dropColumn([
                'photo_consent', 'photo_storage_key', 'photo_sha256', 'photo_captured_at',
            ]);
        });

        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropColumn('require_photo');
        });
    }
};
