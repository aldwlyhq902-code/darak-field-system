import 'package:darak_field/core/numbers.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('an Arabic keyboard quantity parses instead of vanishing', () {
    expect(parseLocalizedNumber('٢'), 2);
    expect(parseLocalizedNumber('١٫٥'), 1.5);
    expect(parseLocalizedNumber('۳'), 3);
    expect(parseLocalizedNumber(' 12.25 '), 12.25);
    expect(parseLocalizedNumber('2,5'), 2.5);
    expect(parseLocalizedNumber('١٢٣٤٥'), 12345);
  });

  test('anything that is not a number stays null', () {
    expect(parseLocalizedNumber(''), isNull);
    expect(parseLocalizedNumber('اثنان'), isNull);
    expect(parseLocalizedNumber('2x'), isNull);
  });

  test('quantities read as people write them', () {
    expect(formatQuantity('2.000'), '2');
    expect(formatQuantity('1.500'), '1.5');
    expect(formatQuantity(3), '3');
    expect(formatQuantity(0.25), '0.25');
    expect(formatQuantity('0.000'), '0');
    expect(formatQuantity(null), '');
    expect(formatQuantity('n/a'), 'n/a');
  });
}
