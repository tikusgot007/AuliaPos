
        if ($inserted === false) {
            // A concurrent delivery may have won the race and stored this
            // message (the unique index blocked us until it committed). Detect
            // that from the DB error itself: a post-failure SELECT would run on
            // this request's REPEATABLE READ snapshot and might not see the
            // winner's just-committed row. Key on the specific index name so a
            // duplicate on another unique key is NOT mistaken for this one.
            $dbError = $db->error();
            $dbMessage = (string) ($dbError['message'] ?? '');
            $isDuplicateWaMessageId = str_contains($dbMessage, 'Duplicate entry')
                && str_contains($dbMessage, 'wa_message_id');

            if ($isDuplicateWaMessageId) {
                return $this->duplicateResponse($waMessageId);
            }

            return $this->response->setStatusCode(500)->setJSON([
                'status'  => 'error',
                'message' => 'Gagal menyimpan pesan (transaksi database gagal).',
            ]);