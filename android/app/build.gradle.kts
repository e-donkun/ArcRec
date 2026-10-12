import java.util.zip.ZipEntry
import java.util.zip.ZipOutputStream

plugins {
    id("com.android.application")
}

// ArcRec 本体(このフォルダの 1 つ上)。版は src/bootstrap.php の LITE_VERSION から取る。
val repoRoot: File = rootProject.projectDir.parentFile
val arcrecVersion: String = Regex("""define\('LITE_VERSION',\s*'([^']+)'\)""")
    .find(repoRoot.resolve("src/bootstrap.php").readText())
    ?.groupValues?.get(1) ?: "0.0.0"
val versionNumbers = arcrecVersion.split('.', '-').mapNotNull { it.toIntOrNull() } + listOf(0, 0, 0)
val arcrecVersionCode: Int = (findProperty("arcrecVersionCode") as String?)?.toInt()
    ?: (versionNumbers[0] * 1_000_000 + versionNumbers[1] * 1_000 + versionNumbers[2]).coerceAtLeast(1)

// アプリに同梱する ArcRec のファイル(リリース ZIP と同じ範囲。tests/ tools/ android/ は入れない)
val webIncludes = listOf(
    "index.php",
    "Api/**",
    "admin/**",
    "src/**",
    "lib/**",
    "data/samples/*.sqlite",
    "LICENSE.txt",
    "NOTICE.md",
    "README.md",
)

// 静的ビルドの PHP(scripts/fetch-php.sh が置く)。Android 10 以降はアプリのデータ領域から実行できないため、
// ネイティブライブラリ(lib*.so)として同梱し、インストール先の nativeLibraryDir から実行する。
val phpBinary = file("src/main/jniLibs/arm64-v8a/libphp.so")

// 署名鍵(環境変数で渡す)。無ければデバッグ鍵で署名する(更新インストールができないので配布には使わない)
val keystorePath: String? = System.getenv("ARCREC_KEYSTORE")
val hasReleaseKey = !keystorePath.isNullOrBlank() && file(keystorePath).isFile

android {
    namespace = "io.github.edonkun.arcrec"
    compileSdk = 35

    defaultConfig {
        applicationId = "io.github.edonkun.arcrec"
        minSdk = 29
        targetSdk = 35
        versionCode = arcrecVersionCode
        versionName = arcrecVersion
    }

    signingConfigs {
        if (hasReleaseKey) {
            create("release") {
                storeFile = file(keystorePath!!)
                storePassword = System.getenv("ARCREC_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("ARCREC_KEY_ALIAS")
                keyPassword = System.getenv("ARCREC_KEY_PASSWORD") ?: System.getenv("ARCREC_KEYSTORE_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName(if (hasReleaseKey) "release" else "debug")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    packaging {
        jniLibs {
            // インストール時に lib*.so をファイルとして展開させる(実行するため)
            useLegacyPackaging = true
            keepDebugSymbols += "**/libphp.so"
        }
    }

    lint {
        abortOnError = false
        checkReleaseBuilds = false
    }
}

dependencies {
    implementation("com.google.zxing:core:3.5.3")
}

/** ArcRec 本体を assets/arcrec.zip にまとめる。アプリは初回起動時・更新後にこれを展開する。 */
abstract class BundleArcRecTask : DefaultTask() {
    @get:InputFiles
    @get:PathSensitive(PathSensitivity.RELATIVE)
    abstract val webFiles: ConfigurableFileCollection

    @get:Internal
    abstract val webRoot: DirectoryProperty

    @get:OutputDirectory
    abstract val outputDir: DirectoryProperty

    @TaskAction
    fun bundle() {
        val root = webRoot.get().asFile
        val out = outputDir.get().asFile
        out.deleteRecursively()
        out.mkdirs()
        val files = webFiles.files.filter { it.isFile }
            .sortedBy { it.relativeTo(root).invariantSeparatorsPath }
        if (files.none { it.relativeTo(root).invariantSeparatorsPath == "index.php" }) {
            throw GradleException("ArcRec の index.php が見つかりません: $root")
        }
        ZipOutputStream(File(out, "arcrec.zip").outputStream().buffered()).use { zip ->
            for (f in files) {
                zip.putNextEntry(ZipEntry(f.relativeTo(root).invariantSeparatorsPath))
                f.inputStream().use { it.copyTo(zip) }
                zip.closeEntry()
            }
        }
    }
}

androidComponents {
    onVariants { variant ->
        val suffix = variant.name.replaceFirstChar { it.uppercase() }
        val bundle = tasks.register<BundleArcRecTask>("bundleArcRec$suffix") {
            webRoot.set(repoRoot)
            webFiles.from(fileTree(repoRoot) { include(webIncludes) })
        }
        variant.sources.assets?.addGeneratedSourceDirectory(bundle, BundleArcRecTask::outputDir)
    }
}

tasks.named("preBuild") {
    doFirst {
        if (!phpBinary.isFile) {
            throw GradleException(
                "PHP がありません: ${phpBinary.path}\n" +
                    "android/scripts/fetch-php.sh を実行して、静的ビルドの PHP を取得してください。"
            )
        }
    }
}
