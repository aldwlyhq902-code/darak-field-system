/// Numbers typed by a technician and numbers sent by the server.
library;

const _arabicIndic = '٠١٢٣٤٥٦٧٨٩';
const _easternArabicIndic = '۰۱۲۳۴۵۶۷۸۹';

/// Parses what an Arabic keyboard types as well as Western digits.
///
/// `double.tryParse('٢')` is null, so a quantity typed on an Arabic keyboard
/// used to be dropped without a word: the dialog closed and nothing was saved.
/// Accepts Arabic-Indic and Eastern Arabic-Indic digits, and `٫` or `,` as the
/// decimal separator. Returns null for anything else.
double? parseLocalizedNumber(String input) {
  final buffer = StringBuffer();
  for (final rune in input.trim().runes) {
    final char = String.fromCharCode(rune);
    final arabic = _arabicIndic.indexOf(char);
    final eastern = _easternArabicIndic.indexOf(char);
    if (arabic >= 0) {
      buffer.write(arabic);
    } else if (eastern >= 0) {
      buffer.write(eastern);
    } else if (char == '٫' || char == ',') {
      buffer.write('.');
    } else {
      buffer.write(char);
    }
  }
  return double.tryParse(buffer.toString());
}

/// A quantity as people write it: "2", "1.5" — never the server's "2.000".
String formatQuantity(Object? value) {
  final number = value is num ? value.toDouble() : double.tryParse('$value');
  if (number == null) return '${value ?? ''}';
  final fixed = number.toStringAsFixed(3);
  return fixed.replaceFirst(RegExp(r'\.?0+$'), '');
}
