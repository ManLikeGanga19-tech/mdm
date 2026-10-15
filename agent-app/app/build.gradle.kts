import java.util.Properties

plugins {
    id("com.android.application")
}

// Firebase push wake-ups: app/google-services.json comes from the Firebase console (project noahport-mdm). Without
// it the agent still builds and works, falling back to the 15-minute check-in.
if (file("google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
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
        versionCode = 9
        versionName = "0.6.0"
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
    implementation(platform("com.google.firebase:firebase-bom:35.0.2"))
    implementation("com.google.firebase:firebase-messaging")
}
