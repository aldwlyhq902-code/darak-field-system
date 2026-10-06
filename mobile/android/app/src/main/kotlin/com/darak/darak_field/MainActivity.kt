package com.darak.darak_field

import android.content.pm.PackageManager
import android.os.Build
import android.os.SystemClock
import android.provider.Settings
import android.view.WindowManager
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File

/**
 * The few answers only the platform can give, on one channel
 * (`com.darak.darak_field/device`, see lib/core/device_platform.dart):
 *
 *  - clock: SystemClock.elapsedRealtime() — unlike the Dart Stopwatch it keeps
 *    counting through deep sleep — plus Settings.Global.BOOT_COUNT, so a
 *    persisted time anchor can be trusted within the same boot only.
 *  - integrity: cheap root/emulator heuristics reported at login (contract C4).
 *    Advisory: never used to block anyone.
 *  - setSecure: FLAG_SECURE, so customer data never lands in screenshots or
 *    the recent-apps thumbnail.
 */
class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "clock" -> result.success(
                        mapOf(
                            "elapsedRealtimeMs" to SystemClock.elapsedRealtime(),
                            "bootCount" to bootCount(),
                        ),
                    )

                    "integrity" -> result.success(
                        mapOf(
                            "rooted" to looksRooted(),
                            "emulator" to looksLikeEmulator(),
                        ),
                    )

                    "setSecure" -> {
                        val enabled = call.argument<Boolean>("enabled") ?: true
                        runOnUiThread {
                            if (enabled) {
                                window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
                            } else {
                                window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
                            }
                        }
                        result.success(null)
                    }

                    else -> result.notImplemented()
                }
            }
    }

    private fun bootCount(): Int =
        try {
            Settings.Global.getInt(contentResolver, Settings.Global.BOOT_COUNT, -1)
        } catch (_: Exception) {
            -1
        }

    private fun looksRooted(): Boolean {
        if (Build.TAGS?.contains("test-keys") == true) return true

        val suPaths = listOf(
            "/system/bin/su",
            "/system/xbin/su",
            "/sbin/su",
            "/su/bin/su",
            "/system/su",
            "/system/bin/.ext/.su",
            "/system/usr/we-need-root/su-backup",
            "/system/app/Superuser.apk",
            "/data/local/su",
            "/data/local/bin/su",
            "/data/local/xbin/su",
            "/data/adb/magisk",
        )
        if (suPaths.any { File(it).exists() }) return true

        // Declared in <queries> in the manifest; without that, Android 11+
        // hides these packages and the check silently answers "no".
        val rootPackages = listOf(
            "com.topjohnwu.magisk",
            "eu.chainfire.supersu",
            "com.noshufou.android.su",
            "com.koushikdutta.superuser",
            "com.thirdparty.superuser",
            "com.yellowes.su",
            "com.kingroot.kinguser",
            "com.kingo.root",
        )
        return rootPackages.any { isInstalled(it) }
    }

    @Suppress("DEPRECATION")
    private fun isInstalled(packageName: String): Boolean =
        try {
            packageManager.getPackageInfo(packageName, 0)
            true
        } catch (_: PackageManager.NameNotFoundException) {
            false
        } catch (_: Exception) {
            false
        }

    private fun looksLikeEmulator(): Boolean {
        val fingerprint = Build.FINGERPRINT.orEmpty()
        val model = Build.MODEL.orEmpty()
        val product = Build.PRODUCT.orEmpty()
        val hardware = Build.HARDWARE.orEmpty()
        val brand = Build.BRAND.orEmpty()
        val device = Build.DEVICE.orEmpty()

        return fingerprint.startsWith("generic") ||
            fingerprint.startsWith("unknown") ||
            fingerprint.contains("emulator") ||
            fingerprint.contains("sdk_gphone") ||
            model.contains("google_sdk") ||
            model.contains("Emulator") ||
            model.contains("Android SDK built for") ||
            Build.MANUFACTURER.orEmpty().contains("Genymotion") ||
            hardware.contains("goldfish") ||
            hardware.contains("ranchu") ||
            hardware.contains("vbox86") ||
            product.contains("sdk") ||
            product.contains("emulator") ||
            product.contains("simulator") ||
            (brand.startsWith("generic") && device.startsWith("generic"))
    }

    companion object {
        private const val CHANNEL = "com.darak.darak_field/device"
    }
}
