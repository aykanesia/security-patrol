package com.securitypatrol.app.ui.login

import android.content.Intent
import android.os.Bundle
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.securitypatrol.app.R
import com.securitypatrol.app.SecurityPatrolApp
import com.securitypatrol.app.data.PatrolRepository
import com.securitypatrol.app.data.local.SessionManager
import com.securitypatrol.app.data.remote.ApiException
import com.securitypatrol.app.databinding.ActivityLoginBinding
import com.securitypatrol.app.ui.home.HomeActivity
import kotlinx.coroutines.launch

class LoginActivity : AppCompatActivity() {

    private lateinit var binding: ActivityLoginBinding
    private val session: SessionManager get() = (application as SecurityPatrolApp).session
    private val repo get() = PatrolRepository(session)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // sudah login? langsung masuk
        if (session.isLoggedIn) {
            goHome()
            return
        }

        binding.btnLogin.setOnClickListener { attemptLogin() }
    }

    private fun attemptLogin() {
        val username = binding.etUsername.text?.toString()?.trim().orEmpty()
        val password = binding.etPassword.text?.toString().orEmpty()

        if (username.isEmpty() || password.isEmpty()) {
            toast(getString(R.string.login_required_fields))
            return
        }

        setLoading(true)
        lifecycleScope.launch {
            try {
                val data = repo.login(username, password)
                val role = data.user?.role.orEmpty()
                if (role != "security") {
                    // backend tetap kasih token, tapi app ini khusus petugas (security)
                    session.logout()
                    toast(getString(R.string.login_role_denied))
                } else {
                    val user = data.user!!
                    session.saveSession(
                        data.token,
                        SessionManager.LoggedUser(
                            id = user.id,
                            name = user.name.orEmpty(),
                            username = username,
                            employeeCode = user.employeeCode.orEmpty(),
                            role = role,
                        ),
                    )
                    goHome()
                }
            } catch (e: ApiException) {
                toast(e.message ?: getString(R.string.login_error_generic))
            } catch (e: Exception) {
                toast(getString(R.string.login_error_generic))
            } finally {
                setLoading(false)
            }
        }
    }

    private fun setLoading(loading: Boolean) {
        binding.btnLogin.isEnabled = !loading
        binding.btnLogin.text = if (loading) {
            getString(R.string.login_loading)
        } else {
            getString(R.string.btn_login)
        }
    }

    private fun goHome() {
        startActivity(Intent(this, HomeActivity::class.java))
        finish()
    }

    private fun toast(msg: String) = Toast.makeText(this, msg, Toast.LENGTH_LONG).show()
}
