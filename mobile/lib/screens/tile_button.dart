import 'package:flutter/material.dart';

import '../l10n/app_localizations.dart';

/// A tonal button for a ListTile's trailing slot (or any Row).
///
/// The theme gives every FilledButton `minimumSize: Size.fromHeight(56)` — an
/// infinite minimum width — so the gloved thumb gets a full-width target. In a
/// trailing slot that means "take the whole tile": in debug the ListTile
/// asserts ("Trailing widget consumes the entire tile width") and the page
/// around it fails to lay out; in release the tile is drawn broken. This keeps
/// the height generous and gives the width a finite minimum.
class TileButton extends StatelessWidget {
  const TileButton({super.key, required this.label, required this.onPressed});

  final String label;
  final VoidCallback? onPressed;

  static final ButtonStyle style = FilledButton.styleFrom(
    minimumSize: const Size(72, 48),
    padding: const EdgeInsets.symmetric(horizontal: 16),
    textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
  );

  @override
  Widget build(BuildContext context) => FilledButton.tonal(
    onPressed: onPressed,
    style: style,
    child: LText(label, maxLines: 1, softWrap: false),
  );
}
