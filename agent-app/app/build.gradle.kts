import java.util.Properties

plugins {
    id("com.android.application")
}

// Release signing key lives outside the repo (CLAUDE.md rule 3). Override the path with
// -Pnoahport.signing=<file> or NOAHPORT_SIGNING; without the file, release builds are unsigned.
val signingFile = file(
    providers.gradleProperty("noahport.signing").orNull
        ?: System.getenv("NOAHPORT_SIGNING")
        ?: "${System.getProperty("user.home")}/.noahport-signing/release.properties"
)
val signing = Properties().apply { if (signingFile.exists()) signingFile.inputStream().use { load(it) } }

android {
    namespace = "com.noahport.mdm.agent"
    compileSdk = 36

    defaultConfig {
        // Permanent: changing it after rollout means re-provisioning every phone (CLAUDE.md rule 6).
        applicationId = "com.noahport.mdm.agent"
        minSdk = 33 // Fleet runs Android 13-15
        targetSdk = 36
        versionCode = 6
        versionName = "0.3.2"
    }

    signingConfigs {
        if (signingFile.exists()) {
            create("release") {
                storeFile = file(signing.getProperty("storeFile"))
                storePassword = signing.getProperty("storePassword")
                keyAlias = signing.getProperty("keyAlias")
                keyPassword = signing.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.findByName("release")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

dependencies {
    implementation("androidx.work:work-runtime-ktx:2.11.2")
}
