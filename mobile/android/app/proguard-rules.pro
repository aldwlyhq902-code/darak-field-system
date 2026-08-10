# Darak technician app — release shrinking rules.
#
# Flutter's own engine classes are kept by the plugin's consumer rules. What is
# listed here are the native-backed plugins whose entry points are reached by
# reflection, so R8 cannot see the reference and would strip them — producing an
# app that runs fine in debug and crashes on a real device the first time a
# technician opens the camera.

# Camera / image picker
-keep class androidx.camera.** { *; }
-keep class io.flutter.plugins.imagepicker.** { *; }

# QR scanning (MLKit barcode)
-keep class com.google.mlkit.** { *; }
-keep class com.google.android.gms.internal.mlkit_** { *; }
-dontwarn com.google.mlkit.**

# Secure storage (keystore-backed)
-keep class androidx.security.crypto.** { *; }

# Location
-keep class com.baseflow.geolocator.** { *; }

# SQLite
-keep class com.tekartik.sqflite.** { *; }

# Keep annotations used for reflection by the above
-keepattributes *Annotation*, Signature, InnerClasses
