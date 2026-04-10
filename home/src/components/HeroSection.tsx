import React from 'react';
import { motion } from 'framer-motion';
import {
  Calendar,
  Clock,
  Users,
  ChevronRight,
  CheckCircle2 } from
'lucide-react';

const APP_URL = import.meta.env.VITE_APP_URL ?? '';
export function HeroSection() {
  return (
    <section className="pt-32 pb-20 md:pt-40 md:pb-32 overflow-hidden">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center max-w-3xl mx-auto mb-16">
          <motion.h1
            initial={{
              opacity: 0,
              y: 20
            }}
            animate={{
              opacity: 1,
              y: 0
            }}
            transition={{
              duration: 0.5
            }}
            className="text-4xl md:text-6xl font-bold text-forest leading-tight mb-6">
            
            Reservation management your restaurant actually needs
          </motion.h1>
          <motion.p
            initial={{
              opacity: 0,
              y: 20
            }}
            animate={{
              opacity: 1,
              y: 0
            }}
            transition={{
              duration: 0.5,
              delay: 0.1
            }}
            className="text-lg md:text-xl text-forest/70 mb-10 leading-relaxed">
            
            Replace paper logs and messy spreadsheets with a clean, real-time
            booking system your whole team can use — from any device, no setup
            required.
          </motion.p>
          <motion.div
            initial={{
              opacity: 0,
              y: 20
            }}
            animate={{
              opacity: 1,
              y: 0
            }}
            transition={{
              duration: 0.5,
              delay: 0.2
            }}
            className="flex flex-col sm:flex-row items-center justify-center gap-4">
            
            <a
              href={`${APP_URL}/register.php`}
              className="w-full sm:w-auto bg-terracotta hover:bg-terracotta-hover text-white px-8 py-4 rounded-full font-semibold text-lg transition-colors shadow-lg shadow-terracotta/20 flex items-center justify-center gap-2">
              
              Start your 30-day free trial
              <ChevronRight size={20} />
            </a>
            <span className="text-sm text-forest/60 flex items-center gap-1.5">
              <CheckCircle2 size={16} className="text-sage" />
              No credit card required
            </span>
          </motion.div>
        </div>

        {/* Product Mockup */}
        <motion.div
          initial={{
            opacity: 0,
            y: 40
          }}
          animate={{
            opacity: 1,
            y: 0
          }}
          transition={{
            duration: 0.7,
            delay: 0.3
          }}
          className="relative mx-auto max-w-5xl">
          
          <div className="bg-white rounded-2xl shadow-2xl border border-sage-light overflow-hidden flex flex-col">
            {/* Browser Header */}
            <div className="bg-cream-dark/50 border-b border-sage-light px-4 py-3 flex items-center gap-2">
              <div className="flex gap-1.5">
                <div className="w-3 h-3 rounded-full bg-red-400"></div>
                <div className="w-3 h-3 rounded-full bg-amber-400"></div>
                <div className="w-3 h-3 rounded-full bg-green-400"></div>
              </div>
              <div className="mx-auto bg-white rounded-md px-32 py-1 text-xs text-forest/40 border border-sage-light">
                app.rezervacije.com
              </div>
            </div>

            {/* App Content */}
            <div className="flex h-[500px]">
              {/* Sidebar */}
              <div className="w-64 border-r border-sage-light bg-cream/30 p-4 hidden md:flex flex-col gap-6">
                <div className="flex items-center gap-2 text-forest font-bold text-lg mb-4">
                  <div className="w-8 h-8 bg-forest rounded-md flex items-center justify-center text-white text-sm">
                    B
                  </div>
                  Bistro Milano
                </div>
                <div className="space-y-1">
                  <div className="flex items-center gap-3 px-3 py-2 bg-white rounded-lg text-forest shadow-sm border border-sage-light font-medium">
                    <Calendar size={18} className="text-terracotta" />
                    Reservations
                  </div>
                  <div className="flex items-center gap-3 px-3 py-2 text-forest/60 hover:bg-white/50 rounded-lg transition-colors">
                    <Users size={18} />
                    Guests
                  </div>
                  <div className="flex items-center gap-3 px-3 py-2 text-forest/60 hover:bg-white/50 rounded-lg transition-colors">
                    <Clock size={18} />
                    Waitlist
                  </div>
                </div>
              </div>

              {/* Main Area */}
              <div className="flex-1 bg-white p-6 flex flex-col gap-6 overflow-hidden">
                {/* Top Bar */}
                <div className="flex justify-between items-center">
                  <h2 className="text-xl font-bold text-forest">
                    Today, Oct 24
                  </h2>
                  <div className="flex gap-2">
                    <button className="px-4 py-1.5 border border-sage-light rounded-lg text-sm font-medium text-forest hover:bg-cream transition-colors">
                      Monthly View
                    </button>
                    <button className="px-4 py-1.5 bg-forest text-white rounded-lg text-sm font-medium">
                      + New Booking
                    </button>
                  </div>
                </div>

                {/* Timeline View Mock */}
                <div className="flex-1 border border-sage-light rounded-xl overflow-hidden flex flex-col">
                  {/* Time Header */}
                  <div className="flex border-b border-sage-light bg-cream/30 text-xs font-medium text-forest/60">
                    <div className="w-20 border-r border-sage-light p-2 text-center">
                      Table
                    </div>
                    <div className="flex-1 grid grid-cols-4">
                      <div className="p-2 border-r border-sage-light">
                        18:00
                      </div>
                      <div className="p-2 border-r border-sage-light">
                        19:00
                      </div>
                      <div className="p-2 border-r border-sage-light">
                        20:00
                      </div>
                      <div className="p-2">21:00</div>
                    </div>
                  </div>

                  {/* Rows */}
                  <div className="flex-1 overflow-hidden flex flex-col">
                    {[1, 2, 3, 4, 5].map((row) =>
                    <div
                      key={row}
                      className="flex border-b border-sage-light flex-1 min-h-[60px]">
                      
                        <div className="w-20 border-r border-sage-light flex items-center justify-center text-sm font-medium text-forest/70 bg-cream/10">
                          T{row}
                        </div>
                        <div className="flex-1 grid grid-cols-4 relative">
                          <div className="border-r border-sage-light border-dashed"></div>
                          <div className="border-r border-sage-light border-dashed"></div>
                          <div className="border-r border-sage-light border-dashed"></div>
                          <div></div>

                          {/* Mock Reservations */}
                          {row === 1 &&
                        <div className="absolute top-2 bottom-2 left-[5%] right-[55%] bg-terracotta/10 border border-terracotta/30 rounded-md p-2 overflow-hidden">
                              <div className="text-xs font-bold text-terracotta-hover">
                                Smith (4)
                              </div>
                              <div className="text-[10px] text-terracotta">
                                18:15 - 19:45
                              </div>
                            </div>
                        }
                          {row === 2 &&
                        <div className="absolute top-2 bottom-2 left-[30%] right-[20%] bg-forest/10 border border-forest/30 rounded-md p-2 overflow-hidden">
                              <div className="text-xs font-bold text-forest">
                                Johnson (2)
                              </div>
                              <div className="text-[10px] text-forest/70">
                                19:00 - 20:30
                              </div>
                            </div>
                        }
                          {row === 4 &&
                        <div className="absolute top-2 bottom-2 left-[60%] right-[5%] bg-sage/20 border border-sage rounded-md p-2 overflow-hidden">
                              <div className="text-xs font-bold text-forest">
                                Williams (6)
                              </div>
                              <div className="text-[10px] text-forest/70">
                                20:00 - 22:00
                              </div>
                            </div>
                        }
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* Decorative elements */}
          <div className="absolute -z-10 top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[120%] h-[120%] bg-gradient-to-b from-sage-light/40 to-transparent rounded-full blur-3xl opacity-50"></div>
        </motion.div>
      </div>
    </section>);

}