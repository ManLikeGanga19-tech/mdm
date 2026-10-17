pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}

rootProject.name = "noahport-mdm-agent"
include(":app")
include(":ussd-probe") // B0 test tool: what Safaricom/Airtel return to a background USSD balance check
