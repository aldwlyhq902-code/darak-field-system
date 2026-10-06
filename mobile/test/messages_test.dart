import 'package:darak_field/core/api_client.dart';
import 'package:darak_field/core/error_messages.dart';
import 'package:darak_field/core/sync_engine.dart';
import 'package:darak_field/core/sync_message.dart';
import 'package:darak_field/core/visit_progress.dart';
import 'package:darak_field/l10n/app_localizations.dart';
import 'package:darak_field/screens/visit_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Findings 3, 12 and 14: what the technician reads.
void main() {
  const english = AppLocalizations(Locale('en'));
  final arabicLetters = RegExp(r'[؀-ۿ]');

  group('sync message (finding 12)', () {
    test('only deferred events is not reported as "synced 0"', () {
      final message = describeSync(
        const SyncOutcome(deferred: 3),
        stillPending: 3,
      );

      expect(message, isNot(contains('تمت مزامنة 0')));
      expect(message, contains('3'));
      expect(
        english.translate(message),
        'Nothing sent yet — 3 waiting for an automatic retry',
      );
    });

    test('real progress is counted across events, actions and uploads', () {
      expect(
        describeSync(
          const SyncOutcome(accepted: 2, actionsSent: 1, uploaded: 2),
          stillPending: 0,
        ),
        'تمت مزامنة 5 عملية',
      );
      expect(
        english.translate(
          describeSync(const SyncOutcome(accepted: 2), stillPending: 4),
        ),
        'Synced 2 operation(s); 4 still waiting for a retry',
      );
      expect(
        describeSync(const SyncOutcome(), stillPending: 0),
        'لا شيء بانتظار المزامنة',
      );
    });
  });

  group('errors in Arabic (finding 14)', () {
    test('transport failures never show the exception text', () {
      final message = friendlyError(
        ApiException(0, const {'code': 'NETWORK'}, cause: 'SocketException: '),
      );
      expect(message, isNot(contains('Socket')));
      expect(arabicLetters.hasMatch(message), isTrue);
    });

    test(
      'known English server copy is mapped, unknown English is not shown',
      () {
        expect(
          friendlyError(
            ApiException(422, const {'message': 'Invalid credentials.'}),
          ),
          'بيانات الدخول غير صحيحة.',
        );
        final unknown = friendlyError(
          ApiException(422, const {
            'message': 'SQLSTATE[23505]: duplicate key',
          }),
        );
        expect(unknown, isNot(contains('SQLSTATE')));
        expect(arabicLetters.hasMatch(unknown), isTrue);
      },
    );

    test('an Arabic server message is passed through', () {
      expect(
        friendlyError(
          ApiException(422, const {'message': 'الكمية غير متاحة.'}),
        ),
        'الكمية غير متاحة.',
      );
    });

    test('every fixed error sentence has an English translation', () {
      final samples = [
        friendlyError(ApiException(0, const {'code': 'NETWORK'})),
        friendlyError(ApiException(0, const {'code': 'TIMEOUT'})),
        friendlyError(ApiException(0, const {'code': 'TLS'})),
        friendlyError(ApiException(503, const {})),
        friendlyError(ApiException(429, const {})),
        friendlyError(ApiException(403, const {})),
        friendlyError(ApiException(404, const {})),
        friendlyError(ApiException(409, const {})),
        friendlyError(ApiException(422, const {})),
        friendlyError(ApiException(403, const {'code': 'DEVICE_REVOKED'})),
        friendlyError(ApiException(403, const {'code': 'USER_INACTIVE'})),
        friendlyError(
          ApiException(403, const {'code': 'DEVICE_PENDING_APPROVAL'}),
        ),
        friendlyError(StateError('boom')),
        friendlyRejection('ACTOR_MISMATCH', null),
        friendlyRejection('EVENT_FAILED', 'Insufficient stock'),
      ];
      for (final sentence in samples) {
        expect(
          arabicLetters.hasMatch(english.translate(sentence)),
          isFalse,
          reason: 'untranslated: $sentence',
        );
      }
    });
  });

  group('visit buttons (finding 3)', () {
    test('every contract edge has its own Arabic and English label', () {
      for (final MapEntry(key: from, value: targets)
          in VisitProgress.transitions.entries) {
        for (final to in targets) {
          final action = VisitTransitionAction.of(from, to);
          expect(
            VisitTransitionAction.all.containsKey('$from>$to'),
            isTrue,
            reason: '$from → $to has no button',
          );
          expect(arabicLetters.hasMatch(action.label), isTrue);
          expect(
            arabicLetters.hasMatch(english.translate(action.label)),
            isFalse,
            reason: 'no English for ${action.label}',
          );
          if (action.confirmation != null) {
            expect(
              arabicLetters.hasMatch(english.translate(action.confirmation!)),
              isFalse,
            );
          }
        }
      }
    });

    test('a reopened visit can be started again', () {
      expect(VisitProgress.transitions['reopened'], contains('started'));
      expect(
        VisitTransitionAction.of('reopened', 'started').label,
        'استئناف العمل',
      );
      expect(
        VisitTransitionAction.of('awaiting_close', 'started').backwards,
        isTrue,
      );
    });
  });
}
