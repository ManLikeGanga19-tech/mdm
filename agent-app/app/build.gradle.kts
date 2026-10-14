plugins {
    id("com.android.application")
}

android {
    namespace = "com.noahport.mdm.agent"
    compileSdk = 36

    defaultConfig {
        // Permanent: changing it after rollout means re-provisioning every phone (CLAUDE.md rule 6).
        applicationId = "com.noahport.mdm.agent"
        minSdk = 33 // Fleet runs Android 13-15
        targetSdk = 36
        versionCode = 1
        versionName = "0.1.0-spike"
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

dependencies {
    implementation("androidx.work:work-runtime-ktx:2.11.2")
}
