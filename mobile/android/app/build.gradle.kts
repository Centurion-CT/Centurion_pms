plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "com.centurionapartments.staff"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.centurionapartments.staff"
        // Android 5.0 (API 21) — Flutter's floor. Covers ~99% of active
        // devices; going lower is not supported by the Flutter engine.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName

        // Only pack English resources into the APK for now — every extra
        // locale ships its own strings + Material translations. Add the
        // languages we actually localise into later.
        resourceConfigurations += listOf("en")
    }

    buildTypes {
        release {
            // Signing with debug keys so operators can sideload the APK
            // straight from Downloads without a Play Store round-trip.
            // Swap for a real keystore before publishing to Play.
            signingConfig = signingConfigs.getByName("debug")

            // R8 shrinking + resource stripping — knocks the release APK
            // down by ~25% by removing unreachable Kotlin/Java code and
            // orphaned drawables/strings.
            isMinifyEnabled = true
            isShrinkResources = true
        }
    }

    // Split by ABI when built with --split-per-abi so operators only
    // download the binary that matches their phone (armeabi-v7a for
    // very old 32-bit devices, arm64-v8a for anything from ~2015 on).
    // Also emit a single universal APK bundling all ABIs so we can
    // hand out one file that installs on anything.
    //
    // x86_64 is dropped intentionally — no consumer Android phone
    // ships an x86_64 CPU (only emulators + a few Chromebooks), so
    // including it costs ~7MB for zero real-world coverage.
    splits {
        abi {
            isEnable = true
            reset()
            include("armeabi-v7a", "arm64-v8a")
            isUniversalApk = true
        }
    }

    // Compress native (.so) libraries inside the APK. Modern default
    // is uncompressed so libs can be mmap'd from the APK (faster
    // first-launch), but at 30MB of native code the download saving
    // is worth the second or two of extra install time. First-launch
    // hit is tiny in practice — the platform decompresses to
    // /data/app/lib/ on install.
    packagingOptions {
        jniLibs {
            useLegacyPackaging = true
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
