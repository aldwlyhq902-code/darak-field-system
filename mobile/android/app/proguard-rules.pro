# SQLCipher. sqflite_sqlcipher 3.4.x depends on net.zetetic:sqlcipher-android
# (4.10.0), whose classes live in net.zetetic.database.** and are reached from
# native code through JNI. The previous rule kept net.sqlcipher.** — the
# package of the old android-database-sqlcipher artifact, which this app no
# longer ships — so R8 was free to strip or rename the classes the native
# library calls into, and a release build could crash on first database open.
# These are the rules the sqlcipher-android README asks for.
-keep,includedescriptorclasses class net.zetetic.database.** { *; }
-keep,includedescriptorclasses interface net.zetetic.database.** { *; }
