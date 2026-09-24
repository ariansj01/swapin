pluginManagement {
    val localProperties = java.util.Properties()
    val localFile = java.io.File(rootDir, "local.properties")
    if (localFile.exists()) {
        java.io.FileInputStream(localFile).use { stream -> localProperties.load(stream) }
    }
    val flutterSdkPath: String = localProperties.getProperty("flutter.sdk")
        ?: throw GradleException("flutter.sdk not found in local.properties")

    includeBuild("$flutterSdkPath/packages/flutter_tools/gradle")

    val storageUrl: String = System.getenv("FLUTTER_STORAGE_BASE_URL") ?: "https://storage.googleapis.com"

    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
        maven { url = uri("$storageUrl/download.flutter.io") }
    }
}

dependencyResolutionManagement {
    val storageUrl: String = System.getenv("FLUTTER_STORAGE_BASE_URL") ?: "https://storage.googleapis.com"

    repositoriesMode.set(RepositoriesMode.PREFER_SETTINGS)
    repositories {
        google()
        mavenCentral()
        maven { url = uri("$storageUrl/download.flutter.io") }
    }
}

rootProject.name = "Swaapin"
include(":app")
