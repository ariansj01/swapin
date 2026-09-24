import java.util.Properties
import java.io.File
import java.io.FileInputStream

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("dev.flutter.flutter-gradle-plugin")
}

flutter {
    source = "../.."
}

android {
    namespace = "ir.swaapin.mobile"
    compileSdk = 34
    buildToolsVersion = "34.0.0"
    ndkVersion = "28.2.13676358"

    defaultConfig {
        applicationId = "ir.swaapin.mobile"
        minSdk = 24
        targetSdk = 34
        versionCode = 1
        versionName = "1.0.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        vectorDrawables {
            useSupportLibrary = true
        }
    }

    signingConfigs {
        create("release") {
            val localProperties = Properties()
            val localFile = rootProject.file("local.properties")
            if (localFile.exists()) {
                localProperties.load(localFile.inputStream())
            }

            val keystorePath = localProperties.getProperty("SWAAPIN_KEYSTORE_PATH")
                ?: System.getenv("SWAAPIN_KEYSTORE_PATH")
                ?: ""
            val keystorePassword = localProperties.getProperty("SWAAPIN_KEYSTORE_PASSWORD")
                ?: System.getenv("SWAAPIN_KEYSTORE_PASSWORD")
                ?: ""
            val keyAlias = localProperties.getProperty("SWAAPIN_KEY_ALIAS")
                ?: System.getenv("SWAAPIN_KEY_ALIAS")
                ?: "swaapin"
            val keyPassword = localProperties.getProperty("SWAAPIN_KEY_PASSWORD")
                ?: System.getenv("SWAAPIN_KEY_PASSWORD")
                ?: ""

            if (keystorePath.isNotEmpty()) {
                storeFile = file(keystorePath)
                storePassword = keystorePassword
                this.keyAlias = keyAlias
                this.keyPassword = keyPassword
            } else {
                val defaultKeystore = rootProject.file("app/swaapin-release.jks")
                if (defaultKeystore.exists()) {
                    storeFile = defaultKeystore
                    storePassword = "Swaapin1234"
                    this.keyAlias = "swaapin"
                    this.keyPassword = "Swaapin1234"
                }
            }
        }
    }

    buildTypes {
        debug {
            isMinifyEnabled = false
            isDebuggable = true
        }
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
            val releaseSigning = signingConfigs.getByName("release")
            if (releaseSigning.storeFile != null && releaseSigning.storeFile?.exists() == true) {
                signingConfig = releaseSigning
            } else {
                signingConfig = signingConfigs.getByName("debug")
            }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.constraintlayout:constraintlayout:2.1.4")
    implementation("androidx.activity:activity-ktx:1.9.0")
    implementation("androidx.swiperefreshlayout:swiperefreshlayout:1.1.0")
    implementation("androidx.webkit:webkit:1.11.0")

    testImplementation("junit:junit:4.13.2")
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.6.1")
}
